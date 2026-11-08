<?php
/**
 * egwmanual tests: custom chapters from an array instead of the filemanager
 *
 * @package egwmanual
 * @subpackage tests
 */

namespace EGroupware\Egwmanual;

class FakeCustom extends Custom
{
	/**
	 * @param array $tree lang => rel. path ("<app>/<file>") => content
	 */
	public function __construct(protected array $tree)
	{
		parent::__construct('/fake');
	}

	/**
	 * The links are built with Egw::link(): without a session there is no framework, a plain one will do
	 */
	public static function framework() : void
	{
		$GLOBALS['egw_info']['server']['webserver_url'] = '/egroupware';
		if (!isset($GLOBALS['egw']->framework))
		{
			$GLOBALS['egw'] ??= new \stdClass();
			$GLOBALS['egw']->framework = new class {
				function link($url, $extravars = '', $link_app = null)
				{
					return \EGroupware\Api\Framework::link($url, $extravars, $link_app);
				}
			};
		}
	}

	public function enabled() : bool
	{
		return true;
	}

	public function languages() : array
	{
		return array_keys($this->tree);
	}

	public function files(string $lang) : array
	{
		$files = [];
		foreach ($this->tree[$lang] ?? [] as $rel => $content)
		{
			if (preg_match(self::MD_PATTERN, $rel) || preg_match(self::IMAGE_PATTERN, $rel))
			{
				$files[$rel] = ['path' => "/fake/$lang/$rel", 'size' => strlen($content), 'mtime' => 1234];
			}
		}
		return $files;
	}

	public function read(string $lang, string $rel, int $max = self::MAX_MD) : ?string
	{
		return isset($this->files($lang)[$rel]) && strlen($this->tree[$lang][$rel]) <= $max ? $this->tree[$lang][$rel] : null;
	}
}
