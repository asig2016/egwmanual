<?php
/**
 * egwmanual - the site configuration: custom chapters are off unless switched on, and only then is the folder checked
 *
 * @package egwmanual
 * @subpackage tests
 */

namespace EGroupware\Egwmanual;

class ConfigTest extends \PHPUnit\Framework\TestCase
{
	public function testSwitchedOn() : void
	{
		foreach ([null, '', false, 0, '0', 'false', 'False', 'off', 'no'] as $value)
		{
			$this->assertFalse(Custom::switchedOn($value), json_encode($value));
		}
		foreach ([true, 1, '1', 'true', 'True', 'on', 'yes'] as $value)
		{
			$this->assertTrue(Custom::switchedOn($value), json_encode($value));
		}
	}

	/**
	 * Switched off, the folder is not looked at: neither checked nor complained about
	 */
	public function testFolderOnlyCheckedWhenSwitchedOn() : void
	{
		$this->assertNull(Hooks::config_validate(['location' => 'config_validate', 'custom_folder' => 'relative']));
		$this->assertNull(Hooks::config_validate(['location' => 'config_validate', 'custom_enabled' => '', 'custom_folder' => '/a/../b']));
		$this->assertMatchesRegularExpression('/folder/i', (string)Hooks::config_validate(['location' => 'config_validate', 'custom_enabled' => 'true']));
		$this->assertMatchesRegularExpression('/absolute/i', (string)Hooks::config_validate(['location' => 'config_validate', 'custom_enabled' => 'true', 'custom_folder' => 'relative']));
	}
}
