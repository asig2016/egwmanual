<?php
/**
 * EGroupware - egwmanual: the administrators' own chapters, kept in a filemanager folder
 *
 * Layout of the folder set in the site configuration:
 *
 *   <folder>/<lang>/<app>/<page>.md          a new chapter, or the replacement of the shipped chapter <page>
 *                                            (an empty file hides that chapter)
 *   <folder>/<lang>/<app>/<page>.append.md   text added to the chapter <page>
 *   <folder>/<lang>/<app>/images/<name>      pictures (png, jpeg, gif, webp) of these chapters
 *
 * <app> is the name of an application or "general" for the chapters that do not belong to one.
 *
 * The folder is read as root (decided: every reader sees every chapter, whatever his filemanager rights), so
 * whoever may write into it publishes to everybody. That trust boundary is guarded by Security::check(): the
 * custom chapters are used only while nobody but the editors can write there. Reading itself is contained:
 * root only for the read, paths only from find() results of the folder (no symlinks followed, no other mounts),
 * names only from strict patterns, sizes limited.
 *
 * @package egwmanual
 */

declare(strict_types=1);

namespace EGroupware\Egwmanual;

use EGroupware\Api;
use EGroupware\Api\Vfs;

class Custom
{
	const MAX_MD = 512 * 1024;
	const MAX_IMAGE = 5 * 1024 * 1024;
	/** files of one language looked at, at most */
	const MAX_FILES = 1000;
	/** a chapter or an append below <app>/ */
	const MD_PATTERN = '#^([a-z0-9_]+)/([a-z0-9][a-z0-9-]*)(\.append)?\.md$#';
	/** a picture below <app>/images/ */
	const IMAGE_PATTERN = '#^([a-z0-9_]+)/images/([A-Za-z0-9][A-Za-z0-9_-]*(?:\.[A-Za-z0-9_-]+)*\.(png|jpe?g|gif|webp))$#';
	const IMAGE_MIME = [
		'png'  => 'image/png',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
	];

	protected static ?Custom $instance = null;

	/** files() of this request: lang => rel => info */
	protected array $files = [];
	/** read() of this request: path => content */
	protected array $contents = [];
	protected ?bool $enabled = null;

	/**
	 * @param ?string $folder null: the one of the site configuration
	 */
	public function __construct(protected ?string $folder = null)
	{
	}

	/**
	 * The custom chapters of this request (tests set their own)
	 */
	public static function get() : Custom
	{
		return self::$instance ??= new Custom();
	}

	public static function set(?Custom $custom) : void
	{
		self::$instance = $custom;
	}

	/**
	 * The folder of the site configuration, normalised; null if none or not an absolute path
	 */
	public function folder() : ?string
	{
		if ($this->folder === null)
		{
			$this->folder = (string)(Api\Config::read(Manual::APP)['custom_folder'] ?? '');
		}
		return self::normalise($this->folder);
	}

	public static function normalise(string $folder) : ?string
	{
		$folder = rtrim(trim($folder), '/');
		if ($folder === '' || $folder[0] !== '/' || preg_match('#(^|/)\.\.?(/|$)|//|[\0\\\\]#', $folder))
		{
			return null;
		}
		return $folder;
	}

	/**
	 * Are the custom chapters used: a folder is set and passes the write-rights check
	 */
	public function enabled() : bool
	{
		return $this->enabled ??= ($folder = $this->folder()) !== null && !Security::problems($folder);
	}

	/**
	 * The languages the folder has a sub-folder for
	 *
	 * @return string[]
	 */
	public function languages() : array
	{
		if (!$this->enabled())
		{
			return [];
		}
		$folder = $this->folder();
		return self::asRoot(function() use ($folder) {
			$langs = [];
			foreach (Vfs::find($folder, ['mindepth' => 1, 'maxdepth' => 1], true) as $path => $stat)
			{
				$name = Vfs::basename($path);
				if (self::isDir($stat) && Manual::validLang($name))
				{
					$langs[] = $name;
				}
			}
			return $langs;
		});
	}

	/**
	 * The chapter and picture files of a language
	 *
	 * @return array rel. path ("<app>/<file>") => ['path' => vfs path, 'size' => int, 'mtime' => int]
	 */
	public function files(string $lang) : array
	{
		if (isset($this->files[$lang]))
		{
			return $this->files[$lang];
		}
		if (!Manual::validLang($lang) || !$this->enabled())
		{
			return $this->files[$lang] = [];
		}
		$base = $this->folder().'/'.$lang;
		return $this->files[$lang] = self::asRoot(function() use ($base) {
			$files = [];
			if (!Vfs::is_dir($base) || Vfs::is_link($base))
			{
				return $files;
			}
			// no symlinks at all: isFile() takes only plain files (find()'s own type "!l" drops plain files too, it
			// tests the mode with & instead of comparing the file type), and find() does not descend into linked
			// folders; hidden files are left out
			foreach (Vfs::find($base, ['mindepth' => 2, 'maxdepth' => 3], true) as $path => $stat)
			{
				if (!self::isFile($stat) || !str_starts_with($path, $base.'/'))
				{
					continue;
				}
				$rel = substr($path, strlen($base) + 1);
				if (!preg_match(self::MD_PATTERN, $rel) && !preg_match(self::IMAGE_PATTERN, $rel))
				{
					continue;
				}
				$files[$rel] = ['path' => $path, 'size' => (int)$stat['size'], 'mtime' => (int)$stat['mtime']];
				if (count($files) >= self::MAX_FILES)
				{
					error_log(__METHOD__."('$base') more than ".self::MAX_FILES.' files, the rest is ignored');
					break;
				}
			}
			return $files;
		});
	}

	/**
	 * The content of a file listed by files(), null if it is not one, too big or unreadable
	 */
	public function read(string $lang, string $rel, int $max = self::MAX_MD) : ?string
	{
		$file = $this->files($lang)[$rel] ?? null;
		if (!$file || $file['size'] > $max)
		{
			if ($file) error_log(__METHOD__."('$lang', '$rel') bigger than $max bytes, ignored");
			return null;
		}
		if (array_key_exists($file['path'], $this->contents))
		{
			return $this->contents[$file['path']];
		}
		return $this->contents[$file['path']] = self::asRoot(static function() use ($file, $max) {
			// it was a plain file of the folder when listed; check again right before reading it
			if (Vfs::is_link($file['path']) || !($stat = Vfs::lstat($file['path'])) || !self::isFile($stat) || $stat['size'] > $max)
			{
				return null;
			}
			$content = @file_get_contents(Vfs::PREFIX.$file['path'], false, null, 0, $max + 1);
			return is_string($content) && strlen($content) <= $max ? $content : null;
		});
	}

	/**
	 * Run $fn as root and restore the previous state, whatever happens
	 *
	 * The stat cache is cleared on both sides: entries cached as root would otherwise be returned to the user later.
	 */
	public static function asRoot(callable $fn) : mixed
	{
		$was_root = Vfs::$is_root;
		Vfs::$is_root = true;
		Vfs::clearstatcache();
		try
		{
			return $fn();
		}
		finally
		{
			Vfs::$is_root = $was_root;
			Vfs::clearstatcache();
		}
	}

	public static function isFile(array $stat) : bool
	{
		return (($stat['mode'] ?? 0) & 0170000) === 0100000;
	}

	public static function isDir(array $stat) : bool
	{
		return (($stat['mode'] ?? 0) & 0170000) === 0040000;
	}
}
