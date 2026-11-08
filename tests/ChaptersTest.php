<?php
/**
 * egwmanual - which chapters a reader has: shipped ones per application, custom ones, replaced, hidden, appended
 *
 * @package egwmanual
 * @subpackage tests
 */

namespace EGroupware\Egwmanual;

require_once __DIR__.'/FakeCustom.php';

class ChaptersTest extends \PHPUnit\Framework\TestCase
{
	const APP = 'zzmanualtest';
	protected $apps;
	protected string $dir;

	protected function setUp() : void
	{
		FakeCustom::framework();
		$this->apps = $GLOBALS['egw_info']['user']['apps'] ?? null;
		$this->dir = Chapters::ROOT.'/'.self::APP.'/doc/manual/el';
		if (!@mkdir($this->dir.'/images', 0777, true) && !is_dir($this->dir.'/images'))
		{
			$this->markTestSkipped("cannot create $this->dir");
		}
		file_put_contents($this->dir.'/98-zz-dokimi.md', "# 98. Δοκιμή\n\n![εικόνα](images/b.png) και [χρήση](00-manual.md)\n\n## 1. Πρώτη\n\nα\n\n### 1.1 Υπο\n\nβ\n\n## 2. Δεύτερη\n\nγ\n");
		file_put_contents($this->dir.'/99-zz-allo.md', "# 99. Άλλο\n\nκείμενο\n");
		file_put_contents($this->dir.'/images/b.png', 'png');
		$GLOBALS['egw_info']['user']['apps'] = [Manual::APP => [], self::APP => []];
		Custom::set(new FakeCustom([]));
		Chapters::reset();
	}

	protected function tearDown() : void
	{
		@unlink($this->dir.'/98-zz-dokimi.md');
		@unlink($this->dir.'/99-zz-allo.md');
		@unlink($this->dir.'/images/b.png');
		@rmdir($this->dir.'/images');
		for ($dir = $this->dir, $up = 0; $up < 4; $up++, $dir = dirname($dir))
		{
			@rmdir($dir);
		}
		if ($this->apps === null)
		{
			unset($GLOBALS['egw_info']['user']['apps']);
		}
		else
		{
			$GLOBALS['egw_info']['user']['apps'] = $this->apps;
		}
		Custom::set(null);
		Chapters::reset();
	}

	protected function custom(array $tree) : void
	{
		Custom::set(new FakeCustom($tree));
		Chapters::reset();
	}

	/**
	 * An application keeps its chapters in its own doc/manual: listed and linked only where the user has the application
	 */
	public function testShippedChaptersOfAnApplication() : void
	{
		$this->assertSame(self::APP, Chapters::app('98-zz-dokimi', 'el'));
		$this->assertSame(Manual::GENERAL, Chapters::app('00-manual', 'el'), 'egwmanual\'s own chapters are the general ones');
		$this->assertSame('98. Δοκιμή', Chapters::title('98-zz-dokimi', 'el'));
		$html = Manual::html('98-zz-dokimi', 'el');
		$this->assertMatchesRegularExpression('#<img src="/egroupware/'.self::APP.'/doc/manual/el/images/b\.png\?\d+"#', $html, 'pictures come from the application');
		$this->assertMatchesRegularExpression('#<a href="[^"]*menuaction=egwmanual\.EGroupware[^"]*page=00-manual[^"]*">χρήση</a>#', $html);
		$this->assertFalse(Chapters::exists('../etc/passwd', 'el'));
		$this->assertSame([], Chapters::index('../el'));

		$GLOBALS['egw_info']['user']['apps'] = [Manual::APP => []];
		Chapters::reset();
		$this->assertFalse(Chapters::exists('98-zz-dokimi', 'el'), 'not for a user without the application');
		$this->assertStringContainsString('<p class="egwmanual_missing">', Manual::html('98-zz-dokimi', 'el'));
		$this->assertTrue(Chapters::exists('00-manual', 'el'), 'the general chapters are always there');
	}

	public function testLanguages() : void
	{
		$langs = Chapters::languages();
		$this->assertContains('el', $langs);
		$this->assertContains('en', $langs);
		$this->custom(['pt-br' => ['general/00-ola.md' => "# Olá\n"], 'xx-yyy' => ['general/00-x.md' => "# x\n"]]);
		$this->assertContains('pt-br', Chapters::languages(), 'a language only the custom folder has');
		$this->assertNotContains('xx-yyy', Chapters::languages());
		$this->assertSame('pt-br', Manual::lang('pt-br'));
		$this->assertNotSame('fr', Manual::lang('fr'), 'a language without chapters is not used');
	}

	public function testCustomChapters() : void
	{
		$this->custom(['el' => [
			self::APP.'/50-zz-neo.md' => "# 50. Νέο\n\n<script>alert(1)</script>\n\n![x](images/c.png)",
			self::APP.'/images/c.png' => 'png',
			self::APP.'/99-zz-allo.md' => "# 99. Αντικατάσταση\n\nνέο κείμενο\n",
			'addressbook/10-ab.md' => "# Επαφές\n",
			'general/00-manual.md' => '',	// hides egwmanual's own chapter
			'zznotinstalled/10-x.md' => "# x\n",
			'general/98-zz-dokimi.md' => "# taken\n",	// the name of another application's chapter
		]]);
		$GLOBALS['egw_info']['user']['apps'] = [Manual::APP => [], self::APP => [], 'addressbook' => []];
		Chapters::reset();
		$index = Chapters::index('el');

		$this->assertSame(['group' => self::APP, 'source' => 'custom'], array_intersect_key($index['50-zz-neo'], ['group' => 1, 'source' => 1]));
		$html = Manual::html('50-zz-neo', 'el');
		$this->assertStringContainsString('&lt;script&gt;', $html, 'custom chapters are escaped as well');
		$this->assertMatchesRegularExpression('#<img src="[^"]*Ui\.image[^"]*name=c\.png#', $html);

		$this->assertSame('override', $index['99-zz-allo']['source']);
		$this->assertSame('99. Αντικατάσταση', Chapters::title('99-zz-allo', 'el'));
		$this->assertStringContainsString('νέο κείμενο', Manual::html('99-zz-allo', 'el'));

		$this->assertSame('addressbook', Chapters::app('10-ab', 'el'), 'an application without shipped chapters gets them');
		$this->assertArrayNotHasKey('00-manual', $index, 'an empty file hides the chapter');
		$this->assertArrayNotHasKey('10-x', $index, 'no chapters of an application the reader does not have');
		$this->assertSame(self::APP, $index['98-zz-dokimi']['group'], 'no taking over another application\'s chapter');
		$this->assertSame('shipped', $index['98-zz-dokimi']['source']);

		$groups = array_column(Chapters::tree('el', '10-ab')['children'], null, 'value');
		$this->assertTrue($groups['app:addressbook']['open']);
		$this->assertSame('egwmanual_local', array_column($groups['app:'.self::APP]['children'], 'class', 'value')['50-zz-neo']);
		$this->assertSame('', array_column($groups['app:'.self::APP]['children'], 'class', 'value')['98-zz-dokimi']);
		$this->assertSame('app:general', Chapters::tree('el')['children'][0]['value'], 'the general chapters first');
	}

	public function testAppends() : void
	{
		$this->custom(['el' => [
			self::APP.'/98-zz-dokimi.append.md' => "Στο τέλος **μας**\n",
			self::APP.'/99-zz-allo.append.md' => "<!-- after: 1-δεν-υπάρχει -->\nάγνωστη ενότητα\n",
			self::APP.'/97-zz-none.append.md' => "χωρίς κεφάλαιο\n",
			'general/98-zz-dokimi.append.md' => "λάθος εφαρμογή\n",
		]]);
		$parts = Chapters::parts('98-zz-dokimi', 'el');
		$this->assertCount(2, $parts);
		$this->assertSame([false, true], array_column($parts, 1));
		$html = Manual::html('98-zz-dokimi', 'el');
		$this->assertMatchesRegularExpression('#<section class="egwmanual_local"><p class="egwmanual_local_label">[^<]+</p><p>Στο τέλος <strong>μας</strong></p></section>$#u', $html);
		$this->assertStringNotContainsString('λάθος εφαρμογή', $html, 'an append of another group does not count');

		$this->assertSame("άγνωστη ενότητα\n", Chapters::parts('99-zz-allo', 'el')[1][0], 'an unknown section: at the end');
		$this->assertArrayNotHasKey('97-zz-none', Chapters::index('el'), 'an append alone is no chapter');

		// after a section: before the next heading of the same or a higher level (the sub-section stays in it)
		$this->custom(['el' => [self::APP.'/98-zz-dokimi.append.md' => "<!-- after: 1-πρώτη -->\nΠροσθήκη\n"]]);
		[$before, $append, $after] = array_column(Chapters::parts('98-zz-dokimi', 'el'), 0);
		$this->assertStringEndsWith("β\n", $before);
		$this->assertSame("Προσθήκη\n", $append);
		$this->assertStringStartsWith('## 2. Δεύτερη', $after);

		// on a replaced chapter, and the after: line is no way to inject anything
		$this->custom(['el' => [
			self::APP.'/98-zz-dokimi.md' => "# Νέα\n\n## Α\n\nα\n",
			self::APP.'/98-zz-dokimi.append.md' => "<!-- after: \"><script> -->\nΠροσθήκη\n",
		]]);
		$html = Manual::html('98-zz-dokimi', 'el');
		$this->assertStringContainsString('Προσθήκη', $html);
		$this->assertStringNotContainsString('<script>', $html);
	}

	public function testSplitIgnoresCode() : void
	{
		[$before, $after] = Chapters::split("# T\n\n## A\n\n```\n## B\n```\n\n## C\n", 'a');
		$this->assertStringContainsString('## B', $before, 'a heading inside fenced code is no heading');
		$this->assertSame('## C', trim($after));
	}

	/**
	 * A live block is replaced by what its application computes, and stays as written without the application or on failure
	 */
	public function testLiveBlocks() : void
	{
		if (!class_exists('zzmanualtest_manual', false))
		{
			class_alias(zzmanualtest_manual::class, 'zzmanualtest_manual');	// the application classes are global
		}
		$md = "Πριν\n\n<!-- live: zzmanualtest.demo a1 -->\n| γραμμένο |\n<!-- /live -->\n\nΜετά\n";
		$this->assertSame("Πριν\n\n| υπολογισμένο el a1 |\n\nΜετά\n", Manual::live($md, 'el'));

		$GLOBALS['egw_info']['user']['apps'] = [Manual::APP => []];
		$this->assertSame("Πριν\n\n| γραμμένο |\n\nΜετά\n", Manual::live($md, 'el'), 'without the application: the text as written');

		$GLOBALS['egw_info']['user']['apps'] = [Manual::APP => [], self::APP => []];
		$this->assertStringContainsString('| γραμμένο |', Manual::live(str_replace('demo', 'broken', $md), 'el'), 'a failing block: the text as written');
		$renderer = Manual::renderer('98-zz-dokimi', 'el');
		$this->assertStringContainsString('&lt;b&gt;', $renderer->render(Manual::live(str_replace('demo', 'html', $md), 'el')), 'a computed block is escaped like the rest');
	}

	/**
	 * Every shipped chapter of the installation renders
	 */
	public function testChaptersOfTheInstallation() : void
	{
		unset($GLOBALS['egw_info']['user']['apps']);	// all applications
		Chapters::reset();
		foreach (Chapters::languages() as $lang)
		{
			foreach (array_keys(Chapters::pages($lang)) as $page)
			{
				$this->assertNotSame('', Manual::html($page, $lang), "$lang/$page");
			}
		}
	}
}

/**
 * The live blocks of the test application zzmanualtest
 */
class zzmanualtest_manual
{
	public static function block_demo(string $lang, string ...$args) : string
	{
		return '| υπολογισμένο '.$lang.' '.implode(' ', $args)." |\n";
	}

	public static function block_broken(string $lang) : string
	{
		throw new \Exception('broken');
	}

	public static function block_html(string $lang) : string
	{
		return "<b>bold</b>\n";
	}
}
