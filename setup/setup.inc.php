<?php
/**
 * EGroupware - egwmanual: the user manual of every application, shown inside EGroupware
 *
 * @link https://www.egroupware.org
 * @package egwmanual
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

use EGroupware\Egwmanual\Hooks;

$setup_info['egwmanual']['name']      = 'egwmanual';
$setup_info['egwmanual']['title']     = 'Manual';
$setup_info['egwmanual']['version']   = '26.0.001';
$setup_info['egwmanual']['app_order'] = 98;
$setup_info['egwmanual']['enable']    = 5;	// not in the navbar: opened from the menu of every application
$setup_info['egwmanual']['index']     = 'egwmanual.EGroupware\\Egwmanual\\Ui.page';
$setup_info['egwmanual']['license']   = 'GPL';
$setup_info['egwmanual']['description'] = 'Shows the user manual of the installed applications (<app>/doc/manual/<lang>/*.md), '.
	'completed by the administrators with own chapters from a filemanager folder.';

$setup_info['egwmanual']['author'] = $setup_info['egwmanual']['maintainer'] = [
	'name'  => 'Alexandros Sigalas',
	'email' => 'asig@sigalas.eu',
];

$setup_info['egwmanual']['depends'][] = [
	'appname'  => 'api',
	'versions' => ['23.1'],
];

$setup_info['egwmanual']['hooks']['sidebox_all']        = Hooks::class.'::sidebox_all';
$setup_info['egwmanual']['hooks']['topmenu_info']       = Hooks::class.'::topmenu_info';
$setup_info['egwmanual']['hooks']['admin']              = Hooks::class.'::admin';
$setup_info['egwmanual']['hooks']['config']             = Hooks::class.'::config';
$setup_info['egwmanual']['hooks']['config_validate']    = Hooks::class.'::config_validate';
$setup_info['egwmanual']['hooks']['config_after_save']  = Hooks::class.'::config_after_save';
