<?php
/**
 * EGroupware - egwmanual: who may write the folder of the custom chapters
 *
 * The custom chapters are read as root and shown to every user of the manual, so everybody who can write into
 * their folder publishes to all of them. They are used only while that circle is the editors: the members of the
 * group set in the site configuration (Admins by default) and root. The folder fails the check if
 *
 * - it is no plain folder of the database filesystem (sqlfs): a symlink, a mount of something else, or
 *   anything else mounted below it,
 * - a folder or file in it is owned by somebody else (an owner can always change the rights),
 * - one is writable by everybody, or writable by its group while that group is not the editors group,
 * - an extended ACL on it or on a folder above it lets somebody else write.
 *
 * The result is cached for a few minutes (and dropped when the configuration is saved); a folder replaced by
 * another one (other inode) is checked at once.
 *
 * @package egwmanual
 */

declare(strict_types=1);

namespace EGroupware\Egwmanual;

use EGroupware\Api;
use EGroupware\Api\Vfs;

class Security
{
	const CACHE_TIME = 300;
	/** entries checked at most, a bigger folder fails */
	const MAX_ENTRIES = 5000;

	/** problems() of this request: key => problems */
	protected static array $checked = [];

	/**
	 * The problems of the folder, cached; [] if the custom chapters may be used
	 *
	 * @return string[] translated messages
	 */
	public static function problems(string $folder) : array
	{
		$ino = Custom::asRoot(static fn() => (Vfs::lstat($folder) ?: [])['ino'] ?? 0);
		$generation = (int)Api\Cache::getInstance(Manual::APP, 'security-generation');
		$key = md5($folder.':'.$ino.':'.self::editorsGroup().':'.$generation);
		if (isset(self::$checked[$key]))
		{
			return self::$checked[$key];
		}
		// cached untranslated: readers of every language share the cache
		$messages = Api\Cache::getInstance(Manual::APP, 'security-'.$key);
		if (!is_array($messages) || array_filter($messages, 'is_string'))
		{
			$messages = self::messages($folder);
			Api\Cache::setInstance(Manual::APP, 'security-'.$key, $messages, self::CACHE_TIME);
			if ($messages)
			{
				error_log(__METHOD__."('$folder') custom chapters NOT used: ".implode('; ', array_map(fn($m) => str_replace(
					array_map(fn($n) => '%'.$n, range(1, count($m) - 1)), array_slice($m, 1), $m[0]), $messages)));
			}
		}
		return self::$checked[$key] = self::translate($messages);
	}

	/**
	 * Forget the cached results (the site configuration was saved)
	 */
	public static function flush() : void
	{
		self::$checked = [];
		// a new generation: the cached results are no longer asked for and run out by themselves
		Api\Cache::setInstance(Manual::APP, 'security-generation', (int)Api\Cache::getInstance(Manual::APP, 'security-generation') + 1);
	}

	/**
	 * The account id of the editors group: the configured one, else Admins
	 */
	public static function editorsGroup(?int $group = null) : int
	{
		if ($group === null)
		{
			$group = (int)(Api\Config::read(Manual::APP)['editors_group'] ?? 0);
		}
		if ($group >= 0)
		{
			$group = (int)Api\Accounts::getInstance()->name2id('Admins', 'account_lid', 'g');
		}
		return $group;
	}

	/**
	 * Check the folder now
	 *
	 * @param ?int $group the editors group, null: the configured one
	 * @return string[] translated messages, [] if the folder is fine
	 */
	public static function check(string $folder, ?int $group = null) : array
	{
		return self::translate(self::messages($folder, $group));
	}

	/**
	 * A message, translated only when shown: the cache is shared by readers of every language
	 *
	 * @return array [phrase, ...arguments]
	 */
	protected static function msg(string $phrase, ...$args) : array
	{
		return [$phrase, ...array_map('strval', $args)];
	}

	/**
	 * @param array[] $messages see msg()
	 * @return string[]
	 */
	protected static function translate(array $messages) : array
	{
		return array_map(static fn($msg) => is_array($msg) ? lang(...$msg) : (string)$msg, $messages);
	}

	/**
	 * Check the folder now, the problems untranslated
	 *
	 * @return array[] see msg(), [] if the folder is fine
	 */
	protected static function messages(string $folder, ?int $group = null) : array
	{
		$folder = Custom::normalise($folder);
		if ($folder === null)
		{
			return [self::msg('The folder has to be an absolute path')];
		}
		$group = self::editorsGroup($group);
		if (!$group)
		{
			return [self::msg('There is no editors group')];
		}
		$editors = array_map('intval', (array)Api\Accounts::getInstance()->members($group, true));
		$is_editor = static fn(int $account) => $account === 0 || $account === $group || in_array($account, $editors, true);
		$name = static fn(int $account) => $account ? (Api\Accounts::id2name($account) ?: '#'.$account) : 'root';

		return Custom::asRoot(static function() use ($folder, $group, $is_editor, $name) : array
		{
			$problems = [];
			if (!($stat = Vfs::lstat($folder)) || !Custom::isDir($stat))
			{
				return [self::msg('%1 is no folder', $folder)];
			}
			// a plain folder of the database filesystem, nothing else mounted into it
			$mounts = (array)Vfs::mount();
			$mount = '';
			foreach (array_keys($mounts) as $path)
			{
				$path = rtrim((string)$path, '/');
				if (($path === '' || $folder === $path || str_starts_with($folder, $path.'/')) && strlen($path) >= strlen($mount))
				{
					$mount = $path;
				}
				if (str_starts_with($path, $folder.'/'))
				{
					$problems[] = self::msg('%1 is mounted into the folder', $path);
				}
			}
			$url = (string)($mounts[$mount === '' ? '/' : $mount] ?? '');
			if (!str_starts_with($url, 'sqlfs://'))
			{
				$problems[] = self::msg('%1 is not stored in the database filesystem', $folder);
			}
			// the folder and every folder above it: no symlink (it would lead somewhere else than configured)
			for ($path = ''; ($pos = strpos($folder.'/', '/', strlen($path) + 1)) !== false; )
			{
				$path = substr($folder, 0, $pos);
				if (Vfs::is_link($path))
				{
					$problems[] = self::msg('%1 is a symlink or lies below one', $folder);
					break;
				}
			}
			if ($problems)
			{
				return $problems;
			}
			$entries = Vfs::find($folder, ['hidden' => true], true);
			if (count($entries) > self::MAX_ENTRIES)
			{
				return [self::msg('The folder holds more than %1 entries', self::MAX_ENTRIES)];
			}
			foreach ($entries as $path => $stat)
			{
				$mode = (int)$stat['mode'];
				if (!$is_editor((int)$stat['uid']))
				{
					$problems[] = self::msg('%1 belongs to %2', $path, $name((int)$stat['uid']));
				}
				if ($mode & 0002)
				{
					$problems[] = self::msg('%1 is writable by everybody', $path);
				}
				if (($mode & 0020) && (int)$stat['gid'] && -abs((int)$stat['gid']) !== $group)
				{
					$problems[] = self::msg('%1 is writable by the group %2', $path, $name(-abs((int)$stat['gid'])));
				}
				// extended ACL of the entry and of the folders above it (up to /)
				foreach ((array)Vfs::get_eacl($path) as $eacl)
				{
					if (((int)$eacl['rights'] & Vfs::WRITABLE) && !$is_editor((int)$eacl['owner']))
					{
						$problems[] = self::msg('%1 grants %2 write access', $eacl['path'], $name((int)$eacl['owner']));
					}
				}
			}
			return array_values(array_unique($problems, SORT_REGULAR));
		});
	}
}
