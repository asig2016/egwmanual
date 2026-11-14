<?php
/**
 * egwmanual - the renderer: the Markdown the manual uses becomes the HTML the window shows, and nothing else
 *
 * @package egwmanual
 * @subpackage tests
 */

namespace EGroupware\Egwmanual;

require_once __DIR__.'/FakeCustom.php';

class RendererTest extends \PHPUnit\Framework\TestCase
{
	protected function renderer() : Renderer
	{
		return new Renderer(
			fn(string $page, string $anchor) => $page === 'nope' ? null : "/chapter?page=$page&anchor=$anchor",
			fn(string $src) => $src === 'images/a.png' ? '/img/a.png?1' : (str_starts_with($src, 'data:image/') ? $src : null));
	}

	public function testBlocksAndInline() : void
	{
		$md = "# Τίτλος **έντονος**\n\nΜια παράγραφος με *πλάγια*, `κώδικα` και <b>escaped</b>.\nΔεύτερη γραμμή.\n\n## 1.1 Ενότητα\n\n- πρώτο\n- δεύτερο\n  - εμφωλευμένο\n- τρίτο\n\n1. ένα\n2. δύο\n\n> **Σημείωση** σημείωση\n\n| Α | Β |\n|---|---|\n| 1 | **2** |\n\n```\n<code> here\n```\n\n---\n";
		$html = $this->renderer()->render($md);
		$this->assertStringContainsString('<h1 id="τίτλος-έντονος">Τίτλος <strong>έντονος</strong></h1>', $html);
		$this->assertStringContainsString('<p>Μια παράγραφος με <em>πλάγια</em>, <code>κώδικα</code> και &lt;b&gt;escaped&lt;/b&gt;. Δεύτερη γραμμή.</p>', $html);
		$this->assertStringContainsString('<h2 id="11-ενότητα">1.1 Ενότητα</h2>', $html);
		$this->assertStringContainsString('<ul><li>πρώτο</li><li>δεύτερο<ul><li>εμφωλευμένο</li></ul></li><li>τρίτο</li></ul>', $html);
		$this->assertStringContainsString('<ol><li>ένα</li><li>δύο</li></ol>', $html);
		$this->assertStringContainsString('<blockquote><p><strong>Σημείωση</strong> σημείωση</p></blockquote>', $html);
		$this->assertStringContainsString('<table class="egwmanual_table"><thead><tr><th>Α</th><th>Β</th></tr></thead><tbody><tr><td>1</td><td><strong>2</strong></td></tr></tbody></table>', $html);
		$this->assertStringContainsString("<pre><code>&lt;code&gt; here</code></pre>", $html);
		$this->assertStringContainsString('<hr>', $html);
	}

	public function testLinksAndImages() : void
	{
		$html = $this->renderer()->inline('Δείτε [το κεφάλαιο](20-aml.md#11-οντότητες), [λείπει](nope.md), [έξω](https://example.org/x?a=1&b=2), [σχέδιο](../../ai/projects/isqm1.md) και ![εικόνα](images/a.png) ![άγνωστη](images/b.png).');
		$this->assertStringContainsString('<a href="/chapter?page=20-aml&amp;anchor=11-οντότητες">το κεφάλαιο</a>', $html);
		$this->assertStringContainsString(' λείπει, ', $html, 'a chapter the reader does not have is plain text');
		$this->assertStringContainsString('<a href="https://example.org/x?a=1&amp;b=2" target="_blank" rel="noopener noreferrer">έξω</a>', $html);
		$this->assertStringContainsString(' σχέδιο και ', $html, 'a repository link is plain text');
		$this->assertStringContainsString('<img src="/img/a.png?1" alt="εικόνα" class="egwmanual_img">', $html);
		$this->assertStringContainsString(' άγνωστη.', $html, 'a picture that may not be shown is its description');
	}

	/**
	 * Whatever a chapter contains, no markup of its own and no active link gets through
	 */
	public function testNothingActiveGetsThrough() : void
	{
		$attacks = [
			'<script>alert(1)</script>',
			'<img src=x onerror=alert(1)>',
			'[x](javascript:alert(1))',
			'[x](JaVaScRiPt:alert(1))',
			'[x](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)',
			'[x](vbscript:msgbox)',
			'[x](https://example.org/"onmouseover="alert(1))',
			'[x](#"onclick="alert(1))',
			'![x](javascript:alert(1))',
			'![x" onerror="alert(1)](images/a.png)',
			'![x](https://tracker.example.org/pixel.gif)',
			'[x](20-aml.md#"><script>)',
			"# <svg onload=alert(1)>",
			"| <iframe src=x> | b |\n|---|---|\n| c | d |",
			'`<script>`',
		];
		foreach ($attacks as $md)
		{
			$html = $this->renderer()->render($md);
			$this->assertDoesNotMatchRegularExpression('/<(script|iframe|svg|object|embed|form|input|style)\b/i', $html, $md);
			// no event handler in any tag (escaped text may well read "onerror=")
			preg_match_all('/<[^>]*>/', $html, $tags);
			$this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', preg_replace('/"[^"]*"/', '""', implode(' ', $tags[0])), $md);
			$this->assertDoesNotMatchRegularExpression('/(href|src)="(?!\/chapter\?|\/img\/|https:\/\/example\.org\/x|#[\p{L}\p{N}_-]+")/u', $html, $md);
			$this->assertStringNotContainsString('tracker.example.org', $html, 'no pictures of other sites');
		}
	}

	/**
	 * Anchors as GitHub makes them, so links written for the repository land on the heading: a dash between spaces leaves two hyphens
	 */
	public function testTableOfContents() : void
	{
		$r = $this->renderer();
		$md = "# 9.9 Τίτλος\n\n> Εισαγωγή\n\n## 1. Πρώτη **έντονη**\n\n### 1.1 Υπο <x>\n\n### 1.2 Άλλη\n\n## 2. Δεύτερη\n\n## 3. Τρίτη\n";
		$html = Renderer::toc($r->render($md), 'Περιεχόμενα');
		$this->assertStringContainsString('</blockquote><nav class="egwmanual_toc"><p class="egwmanual_toc_title">Περιεχόμενα</p><ul>'.
			'<li><a href="#1-πρώτη-έντονη">1. Πρώτη έντονη</a><ul><li><a href="#11-υπο-x">1.1 Υπο &lt;x&gt;</a></li><li><a href="#12-άλλη">1.2 Άλλη</a></li></ul></li>'.
			'<li><a href="#2-δεύτερη">2. Δεύτερη</a></li><li><a href="#3-τρίτη">3. Τρίτη</a></li></ul></nav>'."\n".'<h2 ', $html, 'after the introduction, nested, still escaped');

		$no_intro = Renderer::toc($r->render("# Τ\n\nκείμενο\n\n## Α\n\n## Β\n\n## Γ\n"), 'Contents');
		$this->assertMatchesRegularExpression('#^<h1 id="τ">Τ</h1><nav class="egwmanual_toc">#u', $no_intro, 'without introduction: under the title');

		$short = $r->render("# Τ\n\n> intro\n\n## Α\n\n## Β\n");
		$this->assertSame($short, Renderer::toc($short, 'Contents'), 'fewer than three sections: none');

		$late_quote = Renderer::toc($r->render("# Τ\n\n## Α\n\n> σημείωση\n\n## Β\n\n## Γ\n"), 'Contents');
		$this->assertMatchesRegularExpression('#^<h1 id="τ">Τ</h1><nav#u', $late_quote, 'a quote inside a section is no introduction');
	}

	public function testSlugKeepsEverySpace() : void
	{
		$this->assertSame('12-αντιστοίχιση-πελάτη--οντότητας', Renderer::slug('1.2 Αντιστοίχιση πελάτη – οντότητας'));
		$this->assertSame('3-νέο-παραστατικό-με-το-χέρι', Renderer::slug('3. Νέο παραστατικό με το χέρι'));
	}

	/**
	 * The pictures a chapter may show: data: images, the application's own, the custom folder's - nothing from elsewhere
	 */
	public function testImageUrls() : void
	{
		FakeCustom::framework();
		Custom::set(new FakeCustom(['en' => ['general/images/c.png' => 'x']]));
		Chapters::reset();
		try
		{
			$svg = 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>');
			$this->assertSame($svg, Manual::imageUrl($svg, 'en', Manual::GENERAL, Manual::APP), 'a computed picture');
			$this->assertNull(Manual::imageUrl('data:text/html;base64,PGI+', 'en', Manual::GENERAL, Manual::APP), 'only images as data: URL');
			$this->assertNull(Manual::imageUrl('https://example.org/a.png', 'en', Manual::GENERAL, Manual::APP), 'no pictures of other sites');
			$this->assertMatchesRegularExpression('#menuaction=egwmanual\.EGroupware.*Ui\.image.*name=c\.png#', (string)Manual::imageUrl('images/c.png', 'en', Manual::GENERAL, Manual::APP));
			$this->assertNull(Manual::imageUrl('images/c.png', 'en', 'addressbook', 'addressbook'), 'only the pictures of the chapter\'s own application');
			foreach (['images/../../../etc/passwd', 'images/x/../../setup.inc.php', '../images/a.png', 'images/.hidden.png', 'images/a.php', '/etc/passwd', 'images/a.png?x=1'] as $src)
			{
				$this->assertNull(Manual::imageUrl($src, 'en', Manual::GENERAL, Manual::APP), $src);
			}
		}
		finally
		{
			Custom::set(null);
			Chapters::reset();
		}
	}
}
