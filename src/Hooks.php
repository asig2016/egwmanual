<?php
/**
 * EGroupware - egwmanual: hooks
 *
 * @package egwmanual
 */

declare(strict_types=1);

namespace EGroupware\Egwmanual;

use EGroupware\Api;

class Hooks
{
	/**
	 * "Manual" in the menu of every application that has chapters (in the reader's language or another one)
	 *
	 * The hook runs for every application without a permission check: the reader's right to egwmanual is checked here.
	 */
	public static function sidebox_all($location = null) : void
	{
		$app = (string)($GLOBALS['egw_info']['flags']['currentapp'] ?? '');
		if (empty($GLOBALS['egw_info']['user']['apps'][Manual::APP]) || $app === Manual::APP || !Chapters::visible($app) ||
			!isset($GLOBALS['egw']->framework))
		{
			return;
		}
		try
		{
			$lang = $reader = Manual::lang();
			$page = self::start($app, $lang);
			foreach ($page === null ? Chapters::languages() : [] as $other)
			{
				if (($page = self::start($app, $other)) !== null)
				{
					$lang = $other;
					break;
				}
			}
			if ($page === null)
			{
				return;
			}
			$url = Manual::link($page, '', $lang === $reader ? null : $lang);
			// the menu is built in the application's context: no add_app() of egwmanual there, its phrases would
			// override the application's own ("Manual" is a manual booking in acdms) - only "common" rows are used
			$title = lang(Manual::APP);
			$GLOBALS['egw']->framework->sidebox($app, $title, [
				'manual' => [
					'text' => $title,
					'link' => "javascript:egw_openWindowCentered2('".$url."','egwmanual',920,850,'yes')",
					'icon' => Manual::APP.'/navbar',	// the framework resolves a bare name in the application's own images
					'app'  => Manual::APP,
					'hint' => lang('The manual of this application, as a popup window'),
				],
				'icon' => Manual::APP.'/navbar',
				'sendToBottom' => true,
			]);
		}
		catch (\Throwable $e)
		{
			// the menu of an application never breaks because of the manual
			error_log(__METHOD__."($app) ".$e->getMessage());
		}
	}

	/**
	 * "Manual" in the avatar menu (top right): opens the manual of the application in front
	 *
	 * The menu runs a javascript: entry only as one call with literal arguments (CSP), so the link cannot name the
	 * active application: the window asks its opener for it (active=1, see Ui::page and app.ts).
	 */
	public static function topmenu_info($data = null) : void
	{
		if (empty($GLOBALS['egw_info']['user']['apps'][Manual::APP]) || !isset($GLOBALS['egw']->framework))
		{
			return;
		}
		// the title is egwmanual's "common" row, see sidebox_all()
		$url = Api\Egw::link('/index.php', ['menuaction' => Manual::UI.'.page', 'active' => 1], false);
		Api\Framework::add_topmenu_item(Manual::APP, "javascript:egw_openWindowCentered2('$url','egwmanual',920,850,'yes')",
			lang(Manual::APP), '', 'navbar');
	}

	/**
	 * The chapter the menu entry of an application opens in a language, null if it has none there
	 *
	 * An application may name it with the hook "manual_start" (returning a chapter name); else its first chapter.
	 */
	public static function start(string $app, string $lang) : ?string
	{
		if (Api\Hooks::exists('manual_start', $app) &&
			is_string($start = Api\Hooks::single(['location' => 'manual_start', 'lang' => $lang], $app)) && Manual::validPage($start) &&
			Chapters::app($start, $lang) === $app)
		{
			return $start;
		}
		return Chapters::first($lang, $app);
	}

	/**
	 * Site configuration in the admin application
	 */
	public static function admin($args) : void
	{
		// hooks get ['location' => ...]
		$location = is_array($args) ? ($args['location'] ?? '') : (string)$args;
		$file = [
			'Site Configuration' => Api\Egw::link('/index.php', 'menuaction=admin.admin_config.index&appname='.Manual::APP.'&ajax=true'),
		];
		if ($location === 'admin')
		{
			display_section(Manual::APP, $file);
		}
		else
		{
			display_sidebox(Manual::APP, lang('Admin'), $file);
		}
	}

	/**
	 * Options and defaults of the site configuration
	 */
	public static function config(array $data) : array
	{
		$langs = Api\Translation::get_installed_langs();
		return [
			'sel_options' => [
				'fallback_lang' => $langs,
			],
			'default_values' => [
				'fallback_lang' => isset($langs[Manual::DEFAULT_LANG]) ? Manual::DEFAULT_LANG : (string)array_key_first($langs),
				'editors_group' => Security::editorsGroup(0),
			],
		];
	}

	/**
	 * Check the site configuration before it is saved
	 *
	 * @return ?string error message, null if fine
	 */
	public static function config_validate(array $data) : ?string
	{
		$errors = [];
		if (!empty($data['fallback_lang']) && !Manual::validLang((string)$data['fallback_lang']))
		{
			$errors[] = lang('Invalid language %1', $data['fallback_lang']);
		}
		$group = (int)($data['editors_group'] ?? 0);
		if ($group && ($group >= 0 || !Api\Accounts::getInstance()->exists($group)))
		{
			$errors[] = lang('The editors have to be a group');
		}
		// the folder only counts while the custom chapters are switched on (off by default)
		if (Custom::switchedOn($data['custom_enabled'] ?? null))
		{
			if (trim((string)($data['custom_folder'] ?? '')) === '')
			{
				$errors[] = lang('The custom chapters need a folder');
			}
			elseif (($folder = Custom::normalise((string)$data['custom_folder'])) === null)
			{
				$errors[] = lang('The folder has to be an absolute path');
			}
			elseif (($problems = Security::check($folder, $group)))
			{
				$errors[] = lang('Only the editors may be able to write into the folder of the custom chapters').":\n- ".
					implode("\n- ", array_slice($problems, 0, 20)).(count($problems) > 20 ? "\n- …" : '');
			}
		}
		return $errors ? implode("\n", $errors) : null;
	}

	/**
	 * The configuration was saved: check the folder again
	 */
	public static function config_after_save(array $data) : void
	{
		Security::flush();
	}
}
