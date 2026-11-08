<?php
/**
 * EGroupware - egwmanual: every user may read the manual
 *
 * @package egwmanual
 */

$defaultgroup = $GLOBALS['egw_setup']->add_account('Default', 'Default', 'Group', false, false);
$GLOBALS['egw_setup']->add_acl('egwmanual', 'run', $defaultgroup);
