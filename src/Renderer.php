<?php
/**
 * EGroupware - egwmanual: the Markdown of a chapter turned into the HTML the manual window shows
 *
 * The chapters use a small part of Markdown (headings, paragraphs, lists, tables, quotes, fenced code,
 * bold/italic/code, links, images), so this renderer covers exactly that. Security model: EVERY text is escaped
 * first and only then the fixed set of tags below is added - no HTML of a chapter ever reaches the page, whether
 * the chapter is shipped with an application or written by an administrator into the filemanager.
 *
 * Links and pictures are resolved by the caller (see Manual::html()): a link to another chapter or a picture
 * becomes what the callback returns, or plain text when it returns null.
 *
 * @package egwmanual
 */

declare(strict_types=1);

namespace EGroupware\Egwmanual;

class Renderer
{
	/** @var callable(string $page, string $anchor) : ?string URL of a chapter, null if the reader has no such chapter */
	protected $chapterUrl;
	/** @var callable(string $src) : ?string URL of a picture, null if it may not be shown */
	protected $imageUrl;

	public function __construct(callable $chapterUrl, callable $imageUrl)
	{
		$this->chapterUrl = $chapterUrl;
		$this->imageUrl = $imageUrl;
	}

	/**
	 * Markdown to HTML
	 */
	public function render(string $markdown) : string
	{
		$lines = preg_split("/\r?\n/", $markdown);
		$out = [];
		$paragraph = [];
		$flush = function() use (&$paragraph, &$out) {
			if ($paragraph)
			{
				$out[] = '<p>'.$this->inline(implode(' ', $paragraph)).'</p>';
				$paragraph = [];
			}
		};
		$n = count($lines);
		for ($i = 0; $i < $n; $i++)
		{
			$line = $lines[$i];
			$trim = trim($line);
			if ($trim === '')
			{
				$flush();
				continue;
			}
			if (str_starts_with($trim, '```'))
			{
				$flush();
				$code = [];
				for ($i++; $i < $n && !str_starts_with(trim($lines[$i]), '```'); $i++)
				{
					$code[] = self::escape($lines[$i]);
				}
				$out[] = '<pre><code>'.implode("\n", $code).'</code></pre>';
				continue;
			}
			if (preg_match('/^(#{1,6})\s+(.+?)\s*#*$/', $trim, $m))
			{
				$flush();
				$level = strlen($m[1]);
				$out[] = "<h$level id=\"".self::escape(self::slug($m[2]))."\">".$this->inline($m[2])."</h$level>";
				continue;
			}
			if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trim))
			{
				$flush();
				$out[] = '<hr>';
				continue;
			}
			if (str_starts_with($trim, '>'))
			{
				$flush();
				$quote = [];
				for (; $i < $n && str_starts_with(trim($lines[$i]), '>'); $i++)
				{
					$quote[] = trim(substr(trim($lines[$i]), 1));
				}
				$i--;
				$out[] = '<blockquote>'.$this->render(implode("\n", $quote)).'</blockquote>';
				continue;
			}
			if (str_starts_with($trim, '|') && $i + 1 < $n && preg_match('/^\|?\s*:?-{2,}/', trim($lines[$i + 1])))
			{
				$flush();
				$rows = [];
				for (; $i < $n && str_starts_with(trim($lines[$i]), '|'); $i++)
				{
					$rows[] = trim($lines[$i]);
				}
				$i--;
				$out[] = $this->table($rows);
				continue;
			}
			if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $line))
			{
				$flush();
				$items = [];
				for (; $i < $n; $i++)
				{
					$l = $lines[$i];
					if (trim($l) === '')
					{
						// a blank line ends the list unless the next line is indented or a further item
						if ($i + 1 < $n && preg_match('/^(\s+\S|\s*([-*+]|\d+[.)])\s+)/', $lines[$i + 1]))
						{
							continue;
						}
						break;
					}
					if (!preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $l, $m))
					{
						if ($items && preg_match('/^\s+\S/', $l))
						{
							$items[count($items) - 1]['text'] .= ' '.trim($l);
							continue;
						}
						break;
					}
					$items[] = ['indent' => strlen(str_replace("\t", '    ', $m[1])), 'ordered' => ctype_digit($m[2][0]), 'text' => $m[3]];
				}
				$i--;
				$out[] = $this->list($items);
				continue;
			}
			$paragraph[] = $trim;
		}
		$flush();
		return implode("\n", $out);
	}

	/**
	 * Nested lists from the items' indentation
	 */
	protected function list(array $items) : string
	{
		$html = '';
		$stack = [];	// open lists: [indent, tag]
		foreach ($items as $item)
		{
			$tag = $item['ordered'] ? 'ol' : 'ul';
			while ($stack && $item['indent'] < end($stack)[0])
			{
				$html .= '</li></'.array_pop($stack)[1].'>';
			}
			if ($stack && $item['indent'] === end($stack)[0] && $tag !== end($stack)[1])
			{
				// a numbered list right after a bulleted one (or the other way round) at the same level
				$html .= '</li></'.array_pop($stack)[1].'>';
			}
			if (!$stack || $item['indent'] > end($stack)[0])
			{
				$stack[] = [$item['indent'], $tag];
				$html .= "<$tag>";
			}
			else
			{
				$html .= '</li>';
			}
			$html .= '<li>'.$this->inline($item['text']);
		}
		while ($stack)
		{
			$html .= '</li></'.array_pop($stack)[1].'>';
		}
		return $html;
	}

	protected function table(array $rows) : string
	{
		$cells = fn(string $row) => array_map('trim', explode('|', trim(preg_replace('/^\||\|$/', '', $row))));
		$html = '<table class="egwmanual_table"><thead><tr>';
		foreach ($cells($rows[0]) as $cell)
		{
			$html .= '<th>'.$this->inline($cell).'</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach (array_slice($rows, 2) as $row)
		{
			$html .= '<tr>';
			foreach ($cells($row) as $cell)
			{
				$html .= '<td>'.$this->inline($cell).'</td>';
			}
			$html .= '</tr>';
		}
		return $html.'</tbody></table>';
	}

	/**
	 * Inline markup on an escaped text: images, links, code, bold, italic
	 */
	public function inline(string $text) : string
	{
		$text = self::escape($text);
		$codes = [];
		$text = preg_replace_callback('/`([^`]+)`/', function($m) use (&$codes) {
			$codes[] = '<code>'.$m[1].'</code>';
			return "\0".(count($codes) - 1)."\0";
		}, $text);
		$text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', function($m) {
			// the source is escaped text: undo that for the resolver, escape its answer again
			if (($url = ($this->imageUrl)(htmlspecialchars_decode($m[2], ENT_QUOTES))) === null)
			{
				return $m[1];
			}
			// a picture under images/icons/ is an icon of the screens, set in the running text (a table cell, a step)
			return '<img src="'.self::escape($url).'" alt="'.$m[1].'" '.
				(str_contains($m[2], 'images/icons/') ? 'title="'.$m[1].'" class="egwmanual_icon"' : 'class="egwmanual_img"').'>';
		}, $text);
		$text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function($m) {
			$href = htmlspecialchars_decode($m[2], ENT_QUOTES);
			if (preg_match('#^(?:\./)?([a-z0-9][a-z0-9-]*)\.md(?:\#([\p{L}\p{N}_-]*))?$#u', $href, $l))
			{
				// a chapter this reader does not have (application missing, chapter hidden): plain text
				if (($url = ($this->chapterUrl)($l[1], $l[2] ?? '')) === null)
				{
					return $m[1];
				}
				return '<a href="'.self::escape($url).'">'.$m[1].'</a>';
			}
			if (preg_match('/^#[\p{L}\p{N}_-]+$/u', $href))
			{
				return '<a href="'.self::escape($href).'">'.$m[1].'</a>';
			}
			if (preg_match('#^https?://[^\s"\'<>]+$#i', $href))
			{
				return '<a href="'.self::escape($href).'" target="_blank" rel="noopener noreferrer">'.$m[1].'</a>';
			}
			// anything else (javascript:, data:, a path into the repository): nothing a reader may open
			return $m[1];
		}, $text);
		$text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
		$text = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $text);
		$text = preg_replace('/(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/s', '<em>$1</em>', $text);
		return preg_replace_callback("/\0(\d+)\0/", fn($m) => $codes[(int)$m[1]], $text);
	}

	/**
	 * A table of contents of a rendered chapter: its level 2 and 3 headings, as in-page links
	 *
	 * Placed after the introduction (the first quote, when it comes before the first section), else after the title.
	 * Works on the HTML render() made - every text in it is escaped already, the heading ids are slugs - so the
	 * headings of a local addition are in it too. A chapter of fewer than $min sections gets none.
	 */
	public static function toc(string $html, string $title, int $min = 3) : string
	{
		if (!preg_match_all('#<h([23]) id="([^"]*)">(.*?)</h\1>#s', $html, $matches, PREG_SET_ORDER) ||
			count(array_filter($matches, fn($m) => $m[1] === '2')) < $min)
		{
			return $html;
		}
		$list = '';
		$open = false;	// a level-3 list is open below the last level-2 entry
		foreach ($matches as [, $level, $id, $text])
		{
			// strip_tags only removes the <strong>/<code>/<a> of render(): the text stays escaped
			$entry = '<li><a href="#'.$id.'">'.trim(strip_tags($text)).'</a>';
			if ($level === '3')
			{
				$list .= ($open ? '' : '<ul>').$entry.'</li>';
				$open = true;
				continue;
			}
			$list .= ($open ? '</ul></li>' : ($list === '' ? '' : '</li>')).$entry;
			$open = false;
		}
		$list .= $open ? '</ul></li>' : '</li>';
		$toc = '<nav class="egwmanual_toc"><p class="egwmanual_toc_title">'.self::escape($title).'</p><ul>'.$list.'</ul></nav>';

		$section = strpos($html, '<h2 ');
		if (($quote = strpos($html, '</blockquote>')) !== false && ($section === false || $quote < $section))
		{
			$at = $quote + strlen('</blockquote>');
		}
		elseif (($h1 = strpos($html, '</h1>')) !== false && ($section === false || $h1 < $section))
		{
			$at = $h1 + strlen('</h1>');
		}
		else
		{
			$at = 0;
		}
		return substr($html, 0, $at).$toc.substr($html, $at);
	}

	public static function escape(string $text) : string
	{
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/**
	 * The anchor of a heading: as GitHub makes it, so links written for the repository work on the screen too
	 */
	public static function slug(string $heading) : string
	{
		$s = mb_strtolower(trim(preg_replace('/[*_`]/', '', $heading)));
		$s = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $s);
		// every space its own hyphen, as GitHub does: «Α – Β» becomes «α--β»
		return trim(preg_replace('/\s/u', '-', trim($s)), '-');
	}
}
