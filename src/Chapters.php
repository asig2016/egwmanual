<?php
/**
 * EGroupware - egwmanual: which chapters a reader has, per language
 *
 * Two sources:
 * - shipped: <app>/doc/manual/<lang>/<page>.md of every installed application the reader may run (egwmanual's own
 *   chapters form the group "general"),
 * - custom: the administrators' folder (see Custom), <lang>/<app>/<page>.md: a new chapter, the replacement of the
 *   shipped chapter of the same name in the same application (an empty file hides it), and <page>.append.md:
 *   text added to a chapter, at its end or after a given section (first line "<!-- after: <heading-anchor> -->").
 *
 * Chapter names are global (the Help buttons link to them by name): a custom chapter cannot take over the name of
 * another application's chapter.
 *
 * @package egwmanual
 */

declare(strict_types=1);

namespace EGroupware\Egwmanual;

use EGroupware\Api;

class Chapters
{
	/** the directory of the applications */
	const ROOT = __DIR__.'/../..';
	const PAGE_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

	/** index() of this request: key => page => entry */
	protected static array $index = [];
	/** shippedDirs() of this request */
	protected static array $dirs = [];
	/** chapters an empty custom file hides, per key of index(): page => true */
	protected static array $hidden = [];

	/**
	 * Forget what this request found (tests, a changed configuration)
	 */
	public static function reset() : void
	{
		self::$index = self::$dirs = self::$hidden = [];
	}

	/**
	 * May the reader see the chapters of a group (an application or "general")
	 */
	public static function visible(string $app) : bool
	{
		if ($app === Manual::GENERAL)
		{
			return true;
		}
		if (!preg_match('/^[a-z0-9_]+$/', $app))
		{
			return false;
		}
		$apps = $GLOBALS['egw_info']['user']['apps'] ?? null;
		// without a session (the tests) every application counts
		return !is_array($apps) || isset($apps[$app]);
	}

	/**
	 * The group a folder's chapters belong to
	 */
	protected static function group(string $app) : string
	{
		return $app === Manual::APP ? Manual::GENERAL : $app;
	}

	/**
	 * The directories holding shipped chapters in a language: application => directory
	 */
	public static function shippedDirs(string $lang) : array
	{
		if (!Manual::validLang($lang))
		{
			return [];
		}
		$key = $lang.':'.self::appsKey();
		if (isset(self::$dirs[$key]))
		{
			return self::$dirs[$key];
		}
		$dirs = [];
		foreach (glob(self::ROOT.'/*/doc/manual/'.$lang, GLOB_ONLYDIR) ?: [] as $dir)
		{
			$app = basename(dirname($dir, 3));
			if (self::visible(self::group($app)))
			{
				$dirs[$app] = $dir;
			}
		}
		// egwmanual first, the others by name: on a clash of chapter names the first one wins
		uksort($dirs, fn($a, $b) => ($b === Manual::APP) <=> ($a === Manual::APP) ?: strcmp($a, $b));
		return self::$dirs[$key] = $dirs;
	}

	protected static function appsKey() : string
	{
		$apps = $GLOBALS['egw_info']['user']['apps'] ?? null;
		return is_array($apps) ? md5(implode(',', array_keys($apps))) : '*';
	}

	/**
	 * The chapters of a language
	 *
	 * @return array page => [
	 *	'group'  => application or "general",
	 *	'source' => "shipped", "custom" or "override",
	 *	'dir'    => application whose doc/manual holds the shipped chapter (and its pictures), null for a custom one,
	 *	'file'   => file of the shipped chapter, null for custom and override,
	 *	'custom' => file below the custom language folder ("<app>/<page>.md"), null for a shipped chapter,
	 *	'append' => file below the custom language folder ("<app>/<page>.append.md") or null,
	 * ] in the order of the chapter names
	 */
	public static function index(string $lang) : array
	{
		if (!Manual::validLang($lang))
		{
			return [];
		}
		$custom = Custom::get();
		$key = self::indexKey($lang);
		if (isset(self::$index[$key]))
		{
			return self::$index[$key];
		}
		$index = $hidden = [];
		foreach (self::shippedDirs($lang) as $app => $dir)
		{
			foreach (glob($dir.'/*.md') ?: [] as $file)
			{
				$page = basename($file, '.md');
				if (preg_match(self::PAGE_PATTERN, $page) && !isset($index[$page]))
				{
					$index[$page] = ['group' => self::group($app), 'source' => 'shipped', 'dir' => $app,
						'file' => $file, 'custom' => null, 'append' => null];
				}
			}
		}
		$appends = [];
		foreach (array_keys($custom->files($lang)) as $rel)
		{
			if (!preg_match(Custom::MD_PATTERN, $rel, $m) || !self::visible($m[1]))
			{
				continue;
			}
			[, $group, $page] = $m;
			if (!empty($m[3]))
			{
				$appends[] = [$page, $group, $rel];
				continue;
			}
			if (isset($index[$page]) && $index[$page]['group'] !== $group)
			{
				error_log(__METHOD__."('$lang') custom chapter $rel ignored: $page is a chapter of {$index[$page]['group']}");
				continue;
			}
			if (trim((string)$custom->read($lang, $rel)) === '')
			{
				unset($index[$page]);	// an empty file hides the chapter (or is no chapter at all)
				$hidden[$page] = true;
				continue;
			}
			$index[$page] = ['group' => $group, 'source' => isset($index[$page]) ? 'override' : 'custom',
				'dir' => $index[$page]['dir'] ?? null, 'file' => null, 'custom' => $rel, 'append' => null];
		}
		foreach ($appends as [$page, $group, $rel])
		{
			if (isset($index[$page]) && $index[$page]['group'] === $group)
			{
				$index[$page]['append'] = $rel;
			}
		}
		ksort($index, SORT_STRING);
		self::$hidden[$key] = $hidden;
		return self::$index[$key] = self::parents($index, $lang);
	}

	protected static function indexKey(string $lang) : string
	{
		return $lang.':'.self::appsKey().':'.spl_object_id(Custom::get());
	}

	/**
	 * The chapters an empty custom file hides in a language: page => true
	 */
	public static function hidden(string $lang) : array
	{
		self::index($lang);
		return self::$hidden[self::indexKey($lang)] ?? [];
	}

	/**
	 * The chapters the chapter list shows in a language: those of index($lang), and every chapter the reader has
	 * only in another language (not one hidden in this one), taken from the configured fallback language first
	 * (Manual::otherLangs()). Without them a reader of a language few applications ship chapters in would not see
	 * the manual of the others at all.
	 *
	 * @return array page => index() entry plus 'lang' => the language it is shown in, in the order of the names
	 */
	public static function listed(string $lang) : array
	{
		if (!Manual::validLang($lang))
		{
			return [];
		}
		$listed = array_map(fn($entry) => ['lang' => $lang] + $entry, self::index($lang));
		$hidden = self::hidden($lang);
		foreach (Manual::otherLangs($lang) as $other)
		{
			foreach (self::index($other) as $page => $entry)
			{
				if (!isset($listed[$page]) && !isset($hidden[$page]))
				{
					$listed[$page] = ['lang' => $other] + $entry;
				}
			}
		}
		ksort($listed, SORT_STRING);
		// a parent named in another language counts only if it is listed in the same group
		foreach ($listed as &$entry)
		{
			if ($entry['parent'] !== null && ($listed[$entry['parent']]['group'] ?? null) !== $entry['group'])
			{
				$entry['parent'] = null;
			}
		}
		unset($entry);
		return self::cutLoops($listed, $lang);
	}

	/**
	 * A chapter's first line "<!-- parent: <chapter> -->" makes it a sub-chapter of that chapter
	 *
	 * Only a parent of the same group (application or "general") counts; a missing parent, another group's, or a
	 * loop (a chapter below itself) leaves the chapter at the top of its group.
	 *
	 * @return array the index with "parent" (chapter name or null) in every entry
	 */
	protected static function parents(array $index, string $lang) : array
	{
		foreach ($index as $page => &$entry)
		{
			$parent = self::marker($entry, $lang);
			$entry['parent'] = $parent !== null && $parent !== $page && ($index[$parent]['group'] ?? null) === $entry['group'] ? $parent : null;
		}
		unset($entry);
		return self::cutLoops($index, $lang);
	}

	/**
	 * Cut every loop of parents (a chapter below itself): the chapter where it closes goes to the top of its group
	 */
	protected static function cutLoops(array $index, string $lang) : array
	{
		foreach (array_keys($index) as $page)
		{
			// up the chain: back at the start is a loop, it is cut here
			for ($up = $index[$page]['parent'], $seen = [$page => true]; $up !== null; $up = $index[$up]['parent'] ?? null)
			{
				if (isset($seen[$up]))
				{
					error_log(__METHOD__."('$lang') chapter $page: its parents form a loop, shown at the top of its group");
					$index[$page]['parent'] = null;
					break;
				}
				$seen[$up] = true;
			}
		}
		return $index;
	}

	/** the parent marker, the chapter's first line */
	const PARENT_MARKER = '/^\x{FEFF}?[ \t]*<!--\s*parent:\s*([a-z0-9][a-z0-9-]*)\s*-->[ \t]*(?:\r?\n|$)/u';

	/**
	 * The parent a chapter names on its first line, null if none
	 */
	protected static function marker(array $entry, string $lang) : ?string
	{
		if ($entry['custom'])
		{
			$first = (string)Custom::get()->read($lang, $entry['custom']);
		}
		else
		{
			$fp = @fopen($entry['file'], 'r');
			$first = $fp ? (string)fgets($fp, 512) : '';
			if ($fp) fclose($fp);
		}
		return preg_match(self::PARENT_MARKER, $first, $m) ? $m[1] : null;
	}

	/**
	 * The sub-chapters of a chapter, in the order of their names
	 *
	 * @return string[]
	 */
	public static function children(string $page, string $lang) : array
	{
		return array_keys(array_filter(self::index($lang), fn($entry) => $entry['parent'] === $page));
	}

	public static function exists(string $page, string $lang) : bool
	{
		return isset(self::index($lang)[$page]);
	}

	/**
	 * The group (application or "general") of a chapter, null if the reader has no such chapter
	 */
	public static function app(string $page, string $lang) : ?string
	{
		return self::index($lang)[$page]['group'] ?? null;
	}

	/**
	 * The chapter's own Markdown (without an append), null if there is none
	 */
	public static function markdown(string $page, string $lang) : ?string
	{
		if (!($entry = self::index($lang)[$page] ?? null))
		{
			return null;
		}
		if ($entry['custom'])
		{
			$content = Custom::get()->read($lang, $entry['custom']);
		}
		else
		{
			$content = @file_get_contents($entry['file'], false, null, 0, Custom::MAX_MD + 1);
			$content = is_string($content) && strlen($content) <= Custom::MAX_MD ? $content : null;
		}
		// the parent marker is for the chapter list, not the text
		return $content === null ? null : preg_replace(self::PARENT_MARKER, '', $content, 1);
	}

	/**
	 * The chapter as parts to render: [markdown, is a local addition (append)]
	 *
	 * @return array[] list of [string $markdown, bool $local]
	 */
	public static function parts(string $page, string $lang) : array
	{
		if (($markdown = self::markdown($page, $lang)) === null)
		{
			return [];
		}
		$append = ($rel = self::index($lang)[$page]['append']) ? Custom::get()->read($lang, $rel) : null;
		if ($append === null || trim($append) === '')
		{
			return [[$markdown, false]];
		}
		$after = null;
		if (preg_match('/^\s*<!--\s*after:\s*([\p{L}\p{N}_-]+)\s*-->[ \t]*(?:\r?\n|$)/u', $append, $m))
		{
			$after = $m[1];
			$append = substr($append, strlen($m[0]));
		}
		[$before, $rest] = self::split($markdown, $after);
		return array_values(array_filter([[$before, false], [$append, true], [$rest, false]], fn($part) => trim($part[0]) !== ''));
	}

	/**
	 * Split a chapter after the section with the given heading anchor (before the next heading of the same or a
	 * higher level); no anchor or an unknown one: the whole chapter comes first
	 *
	 * @return string[] [before, after]
	 */
	public static function split(string $markdown, ?string $anchor) : array
	{
		if ($anchor === null || $anchor === '')
		{
			return [$markdown, ''];
		}
		$lines = preg_split("/\r?\n/", $markdown);
		$level = null;
		$fenced = false;
		foreach ($lines as $i => $line)
		{
			$trim = trim($line);
			if (str_starts_with($trim, '```'))
			{
				$fenced = !$fenced;
				continue;
			}
			if ($fenced || !preg_match('/^(#{1,6})\s+(.+?)\s*#*$/', $trim, $m))
			{
				continue;
			}
			if ($level === null)
			{
				if (Renderer::slug($m[2]) === $anchor)
				{
					$level = strlen($m[1]);
				}
			}
			elseif (strlen($m[1]) <= $level)
			{
				return [implode("\n", array_slice($lines, 0, $i)), implode("\n", array_slice($lines, $i))];
			}
		}
		return [$markdown, ''];
	}

	/**
	 * The title of a chapter: its first heading, else its name
	 */
	public static function title(string $page, string $lang) : string
	{
		$entry = self::index($lang)[$page] ?? null;
		if ($entry && $entry['file'])
		{
			// a shipped chapter: only its first lines
			$fp = @fopen($entry['file'], 'r');
			for ($n = 0; $fp && $n < 50 && ($line = fgets($fp)) !== false; $n++)
			{
				if (preg_match('/^#\s+(.+)$/', trim($line), $m))
				{
					fclose($fp);
					return self::plain($m[1]);
				}
			}
			if ($fp) fclose($fp);
		}
		elseif ($entry && preg_match('/^#\s+(.+)$/m', (string)self::markdown($page, $lang), $m))
		{
			return self::plain($m[1]);
		}
		return $page;
	}

	protected static function plain(string $heading) : string
	{
		return trim(preg_replace('/[*_`]/', '', $heading));
	}

	/**
	 * The chapters: name => title, ordered by their name (= number)
	 */
	public static function pages(string $lang) : array
	{
		$pages = [];
		foreach (array_keys(self::index($lang)) as $page)
		{
			$pages[$page] = self::title($page, $lang);
		}
		return $pages;
	}

	/**
	 * The first chapter of a group (application or "general"), or of all if $group is null
	 */
	public static function first(string $lang, ?string $group = null) : ?string
	{
		foreach (self::index($lang) as $page => $entry)
		{
			if ($group === null || $entry['group'] === $group)
			{
				return $page;
			}
		}
		return null;
	}

	/**
	 * The languages the reader has chapters in
	 *
	 * @return string[]
	 */
	public static function languages() : array
	{
		$langs = Custom::get()->languages();
		foreach (glob(self::ROOT.'/*/doc/manual/*', GLOB_ONLYDIR) ?: [] as $dir)
		{
			$langs[] = basename($dir);
		}
		$langs = array_unique(array_filter($langs, [Manual::class, 'validLang']));
		sort($langs);
		return array_values(array_filter($langs, fn($lang) => self::index($lang)));
	}

	/**
	 * The chapter list as tree options: one node per group with its icon, the chapters below in number order;
	 * "general" first, then the applications in the order of their first chapter. Only the group of $current starts
	 * open. The keys are those of Etemplate\Widget\Tree, spelled out: loading that class needs a database.
	 */
	public static function tree(string $lang, string $current = '') : array
	{
		$index = self::listed($lang);
		// the parents of the chapter shown start open
		$open = [];
		for ($up = $index[$current]['parent'] ?? null; $up !== null && !isset($open[$up]); $up = $index[$up]['parent'] ?? null)
		{
			$open[$up] = true;
		}
		$nodes = [];
		foreach ($index as $page => $entry)
		{
			$title = self::title($page, $entry['lang']);
			$nodes[$page] = [
				'value' => $page,
				'label' => $title,
				'title' => $title,
				'class' => $entry['source'] !== 'shipped' || $entry['append'] ? 'egwmanual_local' : '',
			];
			if ($entry['lang'] !== $lang)
			{
				// a chapter missing in this language: shown in the one it has, which the click opens (Ui::page() tree_lang)
				$name = Manual::langName($entry['lang']);
				$nodes[$page]['label'] .= ' ('.$name.')';
				$nodes[$page]['title'] = $title.' - '.Manual::translate('only in %1', $lang, Manual::APP, $name);
				$nodes[$page]['class'] = trim($nodes[$page]['class'].' egwmanual_foreign');
			}
			if (isset($open[$page]))
			{
				$nodes[$page]['open'] = true;
			}
		}
		// sub-chapters below their parent (the parents() of index() made sure there is no loop), deepest first
		$depth = static function(string $page) use ($index) : int {
			for ($n = 0, $up = $index[$page]['parent']; $up !== null; $up = $index[$up]['parent'], $n++);
			return $n;
		};
		$pages = array_keys($nodes);
		usort($pages, fn($a, $b) => $depth($b) <=> $depth($a) ?: strcmp($b, $a));
		foreach ($pages as $page)
		{
			if (($parent = $index[$page]['parent']) !== null)
			{
				// names descending, each put first: the sub-chapters end up in ascending order
				$nodes[$parent]['children'] ??= [];
				array_unshift($nodes[$parent]['children'], $nodes[$page]);
				unset($nodes[$page]);
			}
		}
		$groups = [];
		foreach ($nodes as $page => $node)
		{
			$groups[$index[$page]['group']][] = $node;
		}
		if (isset($groups[Manual::GENERAL]))
		{
			$groups = [Manual::GENERAL => $groups[Manual::GENERAL]] + $groups;
		}
		$current_group = $current !== '' ? self::app($current, $lang) : null;
		$tree = [];
		foreach ($groups as $group => $chapters)
		{
			$app = $group === Manual::GENERAL ? Manual::APP : $group;
			// in the language of the chapters (the reader may have switched it), not the user's
			$label = $group === Manual::GENERAL ? Manual::translate('General', $lang) :
				Manual::translate($app, $lang, $app);
			if ($label === $app && !empty($GLOBALS['egw_info']['apps'][$app]['title']))
			{
				$label = lang($GLOBALS['egw_info']['apps'][$app]['title']);	// no title in that language
			}
			// the icon the framework's application list shows: "kdots-navbar" (one colour, drawn in the application's
			// colour) before "navbar" (white on a circle in the application's colour), see app.ts treeColors()
			$kdots = Api\Image::find($app, 'kdots-navbar') !== null;
			$icon = $app.($kdots ? '/kdots-navbar' : '/navbar');
			$tree[] = [
				'value' => 'app:'.$group,
				'label' => $label,
				'title' => $label,
				'im0' => $icon,
				'im1' => $icon,
				'im2' => $icon,
				'open' => $group === $current_group,
				'unselectable' => true,
				'class' => 'egwmanual_app egwmanual_app_'.$app.($kdots ? ' egwmanual_kdots' : ''),
				'children' => $chapters,
			];
		}
		return ['value' => 0, 'children' => $tree];
	}
}
