<?php
/**
 * EGroupware - egwmanual: the manual of the installed applications, rendered for the manual window
 *
 * What the applications use:
 * - Manual::link($page, $anchor) - the URL of a chapter (a Help button, a menu entry), to open as popup:
 *     egw.openPopup(<url>, 920, 850, 'egwmanual')
 * - a chapter in <app>/doc/manual/<lang>/<page>.md, pictures in images/ next to it
 * - optional "live" parts of a chapter computed by the application (see live())
 *
 * @package egwmanual
 */

declare(strict_types=1);

namespace EGroupware\Egwmanual;

use EGroupware\Api;

class Manual
{
	const APP = 'egwmanual';
	/** the group of the chapters belonging to no application */
	const GENERAL = 'general';
	const UI = 'egwmanual.EGroupware\\Egwmanual\\Ui';
	/** language when nothing else is configured */
	const DEFAULT_LANG = 'en';

	/** shipped pictures: below images/, also in sub-folders (icons/) */
	const SHIPPED_IMAGE = '#^(?:\./)?(images/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9][A-Za-z0-9_-]*(?:\.[A-Za-z0-9_-]+)*\.(?:png|jpe?g|gif|webp|svg))$#';
	/** pictures of the custom folder: directly in images/ */
	const CUSTOM_IMAGE = '#^(?:\./)?images/([A-Za-z0-9][A-Za-z0-9_-]*(?:\.[A-Za-z0-9_-]+)*\.(?:png|jpe?g|gif|webp))$#';

	public static function validLang(?string $lang) : bool
	{
		return is_string($lang) && preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $lang);
	}

	public static function validPage(?string $page) : bool
	{
		return is_string($page) && preg_match(Chapters::PAGE_PATTERN, $page);
	}

	/**
	 * The language to show: the wanted one, else the user's, else the configured fallback, else the first one the
	 * reader has chapters in
	 */
	public static function lang(?string $wanted = null) : string
	{
		$langs = Chapters::languages();
		foreach ([$wanted, $GLOBALS['egw_info']['user']['preferences']['common']['lang'] ?? null, self::fallback()] as $lang)
		{
			if (self::validLang($lang) && in_array($lang, $langs, true))
			{
				return $lang;
			}
		}
		return $langs[0] ?? self::DEFAULT_LANG;
	}

	/** translate() of this request: "app:lang" => phrases of that application's lang file */
	protected static array $phrases = [];

	/**
	 * A phrase in the language of the manual shown, which may differ from the user's own (the language selector)
	 *
	 * Read from the application's own lang file in that language (the global translations stay those of the user);
	 * a phrase missing there falls back to lang(). The application's title is its row tagged "common".
	 */
	public static function translate(string $phrase, string $lang, string $app = self::APP, ...$vars) : string
	{
		if ($lang !== ($GLOBALS['egw_info']['user']['preferences']['common']['lang'] ?? null) && self::validLang($lang) &&
			preg_match('/^[a-z0-9_]+$/', $app))
		{
			$phrases = self::$phrases[$app.':'.$lang] ??= (array)Api\Translation::load_app_files($app, $lang, $app);
			if (isset($phrases[$key = strtolower(trim($phrase))]))
			{
				$translation = $phrases[$key];
				foreach (array_values($vars) as $n => $var)
				{
					$translation = str_replace('%'.($n + 1), (string)$var, $translation);
				}
				return $translation;
			}
		}
		return lang($phrase, ...$vars);
	}

	/**
	 * A language the reader has the chapter in: the configured fallback first, null if none
	 */
	public static function langOf(string $page) : ?string
	{
		$langs = array_filter(Chapters::languages(), fn($lang) => Chapters::exists($page, $lang));
		if (!$langs)
		{
			return null;
		}
		$fallback = self::fallback();
		return in_array($fallback, $langs, true) ? $fallback : reset($langs);
	}

	/**
	 * The configured fallback language, null if none
	 */
	public static function fallback() : ?string
	{
		try
		{
			$fallback = Api\Config::read(self::APP)['fallback_lang'] ?? null;
		}
		catch (\Throwable $e)
		{
			$fallback = null;	// no database (the tests)
		}
		return self::validLang($fallback) ? $fallback : null;
	}

	/**
	 * The other languages the reader has chapters in, in the order a chapter missing in $lang is looked for:
	 * the configured fallback first, then by name
	 *
	 * @return string[]
	 */
	public static function otherLangs(string $lang) : array
	{
		$langs = Chapters::languages();
		if (($fallback = self::fallback()) !== null && in_array($fallback, $langs, true))
		{
			array_unshift($langs, $fallback);
		}
		return array_values(array_diff(array_unique($langs), [$lang]));
	}

	/**
	 * A language in its own name ("Ελληνικά", "Deutsch"), as language choosers show it
	 */
	public static function langName(string $lang) : string
	{
		if (class_exists('Locale'))
		{
			return mb_convert_case(\Locale::getDisplayName($lang, $lang), MB_CASE_TITLE);
		}
		try
		{
			return Api\Translation::get_available_langs()[$lang] ?? $lang;
		}
		catch (\Throwable $e)
		{
			return $lang;
		}
	}

	/**
	 * The URL of a chapter (and an anchor in it), as the help buttons and menu entries use it
	 *
	 * @param ?string $lang null: the reader's language (decided when the window opens)
	 */
	public static function link(string $page, string $anchor = '', ?string $lang = null) : string
	{
		// a plain URL always: while the kdots sidebox renders, Egw::link() wraps a URL into javascript:egw_link_handler(),
		// which nested inside the menu entry's own javascript: call is a syntax error (third argument false = no wrapping)
		return Api\Egw::link('/index.php', array_filter([
			'menuaction' => self::UI.'.page',
			'page' => $page,
			'anchor' => $anchor,
			'lang' => $lang,
		], fn($v) => $v !== null && $v !== ''), false);
	}

	/**
	 * The chapter as HTML: its parts rendered, a local addition marked as such
	 */
	public static function html(string $page, string $lang) : string
	{
		if (!($parts = Chapters::parts($page, $lang)))
		{
			return '<p class="egwmanual_missing">'.Renderer::escape(self::translate('The chapter %1 does not exist', $lang, self::APP, $page)).'</p>';
		}
		$renderer = self::renderer($page, $lang);
		$html = '';
		foreach ($parts as [$markdown, $local])
		{
			$part = $renderer->render(self::live($markdown, $lang));
			$html .= $local ? '<section class="egwmanual_local"><p class="egwmanual_local_label">'.
				Renderer::escape(self::translate('Local addition', $lang)).'</p>'.$part.'</section>' : $part;
		}
		return Renderer::toc($html, self::translate('Contents', $lang));
	}

	/**
	 * The renderer of a chapter: its links and pictures resolved for this reader
	 */
	public static function renderer(string $page, string $lang) : Renderer
	{
		$entry = Chapters::index($lang)[$page] ?? [];
		$group = $entry['group'] ?? self::GENERAL;
		$shipped_app = $entry['dir'] ?? ($group === self::GENERAL ? self::APP : $group);
		return new Renderer(
			static fn(string $page, string $anchor) => self::chapterLink($page, $anchor, $lang),
			static fn(string $src) => self::imageUrl($src, $lang, $group, $shipped_app));
	}

	/**
	 * The URL a link of a chapter in $lang to another chapter goes to, null if the reader has no such chapter
	 *
	 * A chapter missing in $lang opens in a language that has it, as the chapter list shows it (Chapters::listed()),
	 * at its top: the headings, and so the anchors, differ per language.
	 */
	public static function chapterLink(string $page, string $anchor, string $lang) : ?string
	{
		if (Chapters::exists($page, $lang))
		{
			return self::link($page, $anchor, $lang);
		}
		$other = Chapters::listed($lang)[$page]['lang'] ?? null;
		return $other !== null ? self::link($page, '', $other) : null;
	}

	/**
	 * The URL of a picture of a chapter, null if it may not be shown
	 *
	 * - a picture of the custom folder (<lang>/<group>/images/<name>), served by Ui::image(),
	 * - else a picture of the application's shipped manual, served as a static file,
	 * - an image as data: URL (live blocks compute pictures); an <img> runs no script, not even an SVG one.
	 * Nothing else: no pictures from other sites (they would learn who reads what).
	 */
	public static function imageUrl(string $src, string $lang, string $group, ?string $shipped_app) : ?string
	{
		if (preg_match('#^data:image/(svg\+xml|png|jpeg|gif|webp);base64,[A-Za-z0-9+/=]+$#', $src))
		{
			return $src;
		}
		if (preg_match(self::CUSTOM_IMAGE, $src, $m) && ($file = Custom::get()->files($lang)[$group.'/images/'.$m[1]] ?? null))
		{
			return Api\Egw::link('/index.php', [
				'menuaction' => self::UI.'.image',
				'lang' => $lang,
				'app' => $group,
				'name' => $m[1],
				'mtime' => $file['mtime'],
			], false);
		}
		if (preg_match(self::SHIPPED_IMAGE, $src, $m) && $shipped_app && ($dir = Chapters::shippedDirs($lang)[$shipped_app] ?? null) &&
			is_file($file = $dir.'/'.$m[1]))
		{
			// pictures are served with a 10-day cache: the file's mtime in the url makes a replaced picture load at once
			return ($GLOBALS['egw_info']['server']['webserver_url'] ?? '').'/'.$shipped_app.'/doc/manual/'.$lang.'/'.$m[1].'?'.filemtime($file);
		}
		return null;
	}

	/**
	 * Parts of a chapter computed from the instance's own setup
	 *
	 *   <!-- live: myapp.statuses -->
	 *   ... the text as written: what GitHub shows, and the screen when the application is missing or fails ...
	 *   <!-- /live -->
	 *
	 * The block comes from <app>_manual::block_<name>(string $lang, string ...$args) : string (Markdown, rendered and
	 * escaped like the rest), called only for an application the reader may run. HTML comments are invisible on GitHub.
	 * The window runs as egwmanual: a provider loads its own phrases (Api\Translation::add_app('<app>')).
	 */
	public static function live(string $markdown, string $lang) : string
	{
		return preg_replace_callback('/^[ \t]*<!--\s*live:\s*([a-z][a-z0-9_]*)\.([a-z][a-z0-9_]*)((?:[ \t]+[A-Za-z0-9_-]+)*)\s*-->[ \t]*\r?\n(.*?)^[ \t]*<!--\s*\/live\s*-->[ \t]*$/ms',
			static function(array $m) use ($lang) : string
			{
				[, $app, $name, $args, $fallback] = $m;
				$class = $app.'_manual';
				$method = 'block_'.$name;
				if (Chapters::visible($app) && $app !== self::GENERAL && class_exists($class) && is_callable([$class, $method]))
				{
					try
					{
						$block = (string)$class::$method($lang, ...preg_split('/\s+/', trim($args), -1, PREG_SPLIT_NO_EMPTY));
						if (trim($block) !== '')
						{
							return rtrim($block);	// the newline after the closing marker stays
						}
					}
					catch (\Throwable $e)
					{
						error_log(__METHOD__."($app.$name) ".$e->getMessage());
					}
				}
				return rtrim($fallback, "\r\n");
			}, $markdown);
	}
}
