<?php
/**
 * EGroupware - egwmanual: the manual window (Help buttons, the "Manual" entry of every application's menu)
 *
 * @package egwmanual
 */

declare(strict_types=1);

namespace EGroupware\Egwmanual;

use EGroupware\Api;
use EGroupware\Api\Etemplate;

class Ui
{
	public $public_functions = [
		'page'  => true,
		'image' => true,
	];

	/**
	 * One chapter, with the chapter list and the language to move around:
	 * index.php?menuaction=egwmanual.EGroupware\Egwmanual\Ui.page&page=<name>&anchor=<id>[&lang=<lang>][&app=<group>]
	 *
	 * Without a language the reader's own; a chapter missing in the language (the reader switched it) is replaced by
	 * the first chapter of the same application (app=), else by the first one.
	 */
	public function page(array $content = []) : void
	{
		$wanted = isset($_GET['lang']) && Manual::validLang((string)$_GET['lang']) ? (string)$_GET['lang'] : null;
		$lang = Manual::lang($wanted);
		$page = (string)($_GET['page'] ?? '');
		// a Help button names no language: a chapter not in the reader's one opens in a language that has it
		if ($wanted === null && Manual::validPage($page) && !Chapters::exists($page, $lang) && ($other = Manual::langOf($page)))
		{
			$lang = $other;
		}
		if (!Manual::validPage($page) || !Chapters::exists($page, $lang))
		{
			// the manual of an application (app=, e.g. the avatar menu): its start chapter, as its menu entry opens
			// it, in another language if the reader's has none (unless the reader chose the language)
			$app = (string)($_GET['app'] ?? '');
			$start = Chapters::visible($app) && $app !== Manual::GENERAL ? Hooks::start($app, $lang) : null;
			foreach ($start === null && $wanted === null && Chapters::visible($app) ? Chapters::languages() : [] as $other)
			{
				if (($start = Hooks::start($app, $other)) !== null)
				{
					$lang = $other;
					break;
				}
			}
			$page = $start ?? Chapters::first($lang, Manual::GENERAL) ?? Chapters::first($lang) ?? '';
		}
		$langs = Chapters::languages();
		$names = Api\Translation::get_available_langs();
		$content = [
			'lang' => $lang,
			// a chapter of the tree navigates (a page the Back button returns to): the link is built here, a namespaced
			// menuaction built on the client gets its backslashes encoded twice
			'page_url_base' => Api\Egw::link('/index.php', ['menuaction' => Manual::UI.'.page']),
			'page' => $page,
			'app' => (string)Chapters::app($page, $lang),
			// the chapter list: the current chapter selected, its application open; hidden while the user wants it so
			'tree' => $page,
			'tree_class' => ($GLOBALS['egw_info']['user']['preferences'][Manual::APP]['tree'] ?? '') === 'hidden' ? 'egwmanual_notree' : '',
			'anchor' => preg_replace('/[^\p{L}\p{N}_-]/u', '', (string)($_GET['anchor'] ?? '')),
			'title' => $page !== '' ? Chapters::title($page, $lang) : lang('Manual'),
			'body' => $page !== '' ? Manual::html($page, $lang) : '<p class="egwmanual_missing">'.
				Renderer::escape(Manual::translate('There is no chapter in this language', $lang)).'</p>',
			'warning' => self::warning(),
			// opened from the avatar menu: the window asks its opener which application is active (app.ts)
			'ask_opener' => !empty($_GET['active']) && !isset($_GET['page']) && !isset($_GET['app']),
		];
		$sel_options = [
			'tree' => Chapters::tree($lang, $page),
			// every language in its own name, as language choosers do (core translates only a few language names)
			'lang' => array_combine($langs, array_map(fn($l) => class_exists('Locale') ?
				mb_convert_case(\Locale::getDisplayName($l, $l), MB_CASE_TITLE) : ($names[$l] ?? $l), $langs)) ?: [$lang => $names[$lang] ?? $lang],
		];
		$readonlys = ['lang' => count($langs) < 2];
		$GLOBALS['egw_info']['flags']['app_header'] = lang('Manual').': '.$content['title'];
		$tmpl = new Etemplate('egwmanual.manual');
		$tmpl->exec(Manual::UI.'.page', $content, $sel_options, $readonlys, ['lang' => $lang], 2);
	}

	/**
	 * For administrators: why the custom chapters are not shown
	 */
	protected static function warning() : string
	{
		if (empty($GLOBALS['egw_info']['user']['apps']['admin']) || ($folder = Custom::get()->folder()) === null ||
			!($problems = Security::problems($folder)))
		{
			return '';
		}
		return lang('The custom chapters of %1 are not shown', $folder).': '.implode('; ', array_slice($problems, 0, 5)).
			(count($problems) > 5 ? ' …' : '');
	}

	/**
	 * A picture of a custom chapter: index.php?menuaction=egwmanual.EGroupware\Egwmanual\Ui.image&lang=<lang>&app=<group>&name=<file>
	 *
	 * Only a file listed for <lang>/<group>/images/ of the custom folder (see Custom::files()), only a raster image
	 * whose content matches its extension (no SVG: opened on its own it could run script), sent so that the browser
	 * neither sniffs another type nor runs anything.
	 */
	public function image() : void
	{
		$name = (string)($_GET['name'] ?? '');
		if (!($image = self::imageContent((string)($_GET['lang'] ?? ''), (string)($_GET['app'] ?? ''), $name)))
		{
			self::notFound();
		}
		[$content, $mime] = $image;
		$length = strlen($content);
		// not nocache: the URL carries the file's mtime; inline, as the image of the page
		Api\Header\Content::safe($content, $name, $mime, $length, false, false);
		header('Content-Security-Policy: default-src \'none\'; sandbox');
		header('Cache-Control: private, max-age=864000');
		echo $content;
		exit;
	}

	/**
	 * The checked picture for image(): [content, mime type], null if it may not be served
	 */
	public static function imageContent(string $lang, string $app, string $name) : ?array
	{
		$rel = $app.'/images/'.$name;
		if (!Manual::validLang($lang) || !Chapters::visible($app) || !preg_match(Custom::IMAGE_PATTERN, $rel, $m) ||
			($content = Custom::get()->read($lang, $rel, Custom::MAX_IMAGE)) === null)
		{
			return null;
		}
		$mime = Custom::IMAGE_MIME[strtolower($m[3])];
		if ((new \finfo(FILEINFO_MIME_TYPE))->buffer($content) !== $mime)
		{
			error_log(__METHOD__."('$lang', '$rel') content is no $mime, refused");
			return null;
		}
		return [$content, $mime];
	}

	protected static function notFound() : never
	{
		http_response_code(404);
		header('Content-Type: text/plain');
		header('X-Content-Type-Options: nosniff');
		echo 'Not found';
		exit;
	}
}
