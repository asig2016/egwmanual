<?php
/**
 * egwmanual - the custom chapters in the filemanager: read as root but contained, and used only while nobody but the
 * editors can write into their folder
 *
 * Works in a folder of its own (/egwmanual-test-<id> and a sibling with "outside" content), removed again.
 *
 * @package egwmanual
 * @subpackage tests
 */

namespace EGroupware\Egwmanual;

use EGroupware\Api;
use EGroupware\Api\Vfs;

require_once realpath(__DIR__.'/../../api/tests/LoggedInTest.php');
require_once __DIR__.'/FakeCustom.php';

class VfsSecurityTest extends Api\LoggedInTest
{
	/** a 1x1 PNG */
	const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

	protected string $folder;
	protected string $outside;
	protected int $admins;
	protected array $eacls = [];

	protected function setUp() : void
	{
		parent::setUp();
		$this->admins = (int)Api\Accounts::getInstance()->name2id('Admins', 'account_lid', 'g');
		if (!$this->admins)
		{
			$this->markTestSkipped('no group Admins');
		}
		$id = bin2hex(random_bytes(4));
		$this->folder = '/egwmanual-test-'.$id;
		$this->outside = '/egwmanual-test-'.$id.'-outside';
		Custom::asRoot(function()
		{
			$this->assertTrue(Vfs::mkdir($this->folder.'/en/general/images', 0755, true));
			$this->assertTrue(Vfs::mkdir($this->outside.'/dir', 0755, true));
			$this->put($this->folder.'/en/general/90-zz-test.md', "# 90. Test\n\nText of the test.\n\n![pic](images/pic.png) ![svg](images/fake.png)\n");
			$this->put($this->folder.'/en/general/90-zz-test.append.md', "Appended.\n");
			$this->put($this->folder.'/en/general/images/pic.png', base64_decode(self::PNG));
			$this->put($this->folder.'/en/general/images/fake.png', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
			$this->put($this->folder.'/en/general/images/page.gif', '<html><script>alert(1)</script></html>');
			$this->put($this->outside.'/secret.md', "# Secret\n\nnot for the manual\n");
			$this->put($this->outside.'/dir/91-zz-indir.md', "# In a linked folder\n");
			foreach ([$this->folder, $this->outside] as $base)
			{
				foreach (array_keys(Vfs::find($base, ['hidden' => true], true)) as $path)
				{
					Vfs::chown($path, 0);
					Vfs::chgrp($path, 0);
					Vfs::chmod($path, Vfs::is_dir($path) ? 0755 : 0644);
				}
			}
		});
		Security::flush();
		Chapters::reset();
		Custom::set(new Custom($this->folder));
		FakeCustom::framework();
	}

	protected function tearDown() : void
	{
		Custom::set(null);
		Chapters::reset();
		Security::flush();
		Custom::asRoot(function()
		{
			foreach ($this->eacls as [$path, $owner])
			{
				Vfs::eacl($path, 0, $owner);
			}
			foreach ([$this->folder, $this->outside] as $base)
			{
				if (Vfs::file_exists($base))
				{
					Vfs::remove($base);
				}
			}
		});
		$this->assertFalse(Vfs::$is_root, 'root is given back');
		parent::tearDown();
	}

	protected function put(string $path, string $content) : void
	{
		$this->assertNotFalse(file_put_contents(Vfs::PREFIX.$path, $content), $path);
	}

	/**
	 * A fresh look at the folder (as the next request would have it)
	 */
	protected function fresh() : Custom
	{
		Security::flush();
		Chapters::reset();
		Custom::set($custom = new Custom($this->folder));
		return $custom;
	}

	/**
	 * A user that is not an editor (member of Admins)
	 */
	protected function nonEditor() : int
	{
		$admins = array_map('intval', (array)Api\Accounts::getInstance()->members($this->admins, true));
		foreach (array_keys((array)$GLOBALS['egw']->accounts->search(['type' => 'accounts', 'start' => 0, 'num_rows' => 200])) as $id)
		{
			if (!in_array((int)$id, $admins, true))
			{
				return (int)$id;
			}
		}
		$this->markTestSkipped('every user is an Admin');
	}

	public function testCleanFolderIsUsed() : void
	{
		$this->assertSame([], Security::check($this->folder, $this->admins));
		$custom = $this->fresh();
		$this->assertTrue($custom->enabled());
		$this->assertContains('en', $custom->languages());
		$this->assertTrue(Chapters::exists('90-zz-test', 'en'));
		$this->assertSame(Manual::GENERAL, Chapters::app('90-zz-test', 'en'));
		$html = Manual::html('90-zz-test', 'en');
		$this->assertStringContainsString('Text of the test.', $html);
		$this->assertStringContainsString('Appended.', $html);
		$this->assertMatchesRegularExpression('#<img src="[^"]*Ui\.image[^"]*name=pic\.png#', $html);
		$this->assertFalse(Vfs::$is_root);
	}

	public function testOnlyRealPicturesAreServed() : void
	{
		$this->fresh();
		$this->assertSame('image/png', Ui::imageContent('en', 'general', 'pic.png')[1] ?? null);
		$this->assertNull(Ui::imageContent('en', 'general', 'fake.png'), 'an SVG named .png');
		$this->assertNull(Ui::imageContent('en', 'general', 'page.gif'), 'HTML named .gif');
		foreach ([['en', 'general', '../90-zz-test.md'], ['en', 'general', '..%2F90-zz-test.md'], ['en', '../general', 'pic.png'],
			['../en', 'general', 'pic.png'], ['en', 'general', 'missing.png'], ['en', 'general', '.pic.png'], ['de', 'general', 'pic.png']] as $args)
		{
			$this->assertNull(Ui::imageContent(...$args), implode('/', $args));
		}
	}

	public function testSymlinksAreNotFollowed() : void
	{
		Custom::asRoot(function()
		{
			$this->assertTrue(Vfs::symlink($this->outside.'/secret.md', $this->folder.'/en/general/91-zz-secret.md'));
			$this->assertTrue(Vfs::symlink($this->outside.'/dir', $this->folder.'/en/linked'));
			$this->assertTrue(Vfs::symlink($this->outside.'/secret.md', $this->folder.'/en/general/images/link.png'));
		});
		$custom = $this->fresh();
		$this->assertTrue($custom->enabled(), 'a symlink makes nothing writable');
		$this->assertFalse(Chapters::exists('91-zz-secret', 'en'), 'a symlinked chapter');
		$this->assertFalse(Chapters::exists('91-zz-indir', 'en'), 'a chapter in a symlinked folder');
		$this->assertArrayNotHasKey('general/images/link.png', $custom->files('en'));
		$this->assertNull($custom->read('en', 'general/91-zz-secret.md'));
	}

	public function testWritableForEverybody() : void
	{
		Custom::asRoot(fn() => Vfs::chmod($this->folder.'/en/general/90-zz-test.md', 0666));
		$this->assertNotSame([], Security::check($this->folder, $this->admins));
		$this->assertFalse($this->fresh()->enabled());
		$this->assertFalse(Chapters::exists('90-zz-test', 'en'), 'custom chapters are not used at all');
		$this->assertTrue(Chapters::exists('00-manual', 'en'), 'the shipped ones are');
	}

	public function testFolderOfAnotherUser() : void
	{
		$user = $this->nonEditor();
		Custom::asRoot(fn() => Vfs::chown($this->folder.'/en/general', $user));
		$problems = Security::check($this->folder, $this->admins);
		$this->assertCount(1, $problems);
		$this->assertStringContainsString($this->folder.'/en/general', $problems[0]);
		$this->assertFalse($this->fresh()->enabled());
	}

	public function testGroupWritableForAnotherGroup() : void
	{
		$group = (int)Api\Accounts::getInstance()->name2id('Default', 'account_lid', 'g');
		if (!$group || $group === $this->admins)
		{
			$this->markTestSkipped('no group Default');
		}
		Custom::asRoot(function() use ($group)
		{
			Vfs::chgrp($this->folder.'/en', $group);
			Vfs::chmod($this->folder.'/en', 0775);
		});
		$this->assertNotSame([], Security::check($this->folder, $this->admins));
		$this->assertSame([], Security::check($this->folder, $group), 'fine when that group is the editors group');
	}

	public function testExtendedAclForAnotherUser() : void
	{
		$user = $this->nonEditor();
		$path = $this->folder.'/en';
		Custom::asRoot(fn() => Vfs::eacl($path, Vfs::READABLE | Vfs::WRITABLE | Vfs::EXECUTABLE, $user));
		$this->eacls[] = [$path, $user];
		$this->assertNotSame([], Security::check($this->folder, $this->admins));

		Custom::asRoot(fn() => Vfs::eacl($path, Vfs::READABLE | Vfs::EXECUTABLE, $user));
		$this->assertSame([], Security::check($this->folder, $this->admins), 'reading is fine');
	}

	public function testFolderMustBeAPlainAbsolutePath() : void
	{
		foreach (['', 'manual', '/a/../b', '/a/./b', '//a', "/a\0b", '/a\\b'] as $folder)
		{
			$this->assertNull(Custom::normalise($folder), json_encode($folder));
		}
		$this->assertSame('/a/b', Custom::normalise('/a/b/'));
		$this->assertNotSame([], Security::check($this->folder.'/en/general/90-zz-test.md', $this->admins), 'a file');
		$this->assertNotSame([], Security::check($this->folder.'-missing', $this->admins));
		$this->assertNotSame([], Security::check('/apps', $this->admins), 'another filesystem');
		Custom::asRoot(fn() => Vfs::symlink($this->folder, $this->outside.'/link'));
		$this->assertNotSame([], Security::check($this->outside.'/link', $this->admins), 'a symlink');
	}

	public function testRootIsGivenBackOnErrors() : void
	{
		$secret = $this->outside.'/secret.md';
		Custom::asRoot(fn() => Vfs::chmod($secret, 0600));
		Vfs::clearstatcache();
		$this->assertFalse(Vfs::is_readable($secret), 'precondition: the user cannot read it');
		try
		{
			Custom::asRoot(function() use ($secret)
			{
				Vfs::stat($secret);	// fills the stat cache as root
				throw new \RuntimeException('boom');
			});
		}
		catch (\RuntimeException $e)
		{
		}
		$this->assertFalse(Vfs::$is_root);
		$this->assertFalse(Vfs::is_readable($secret), 'nothing of root left behind');
	}
}
