<?php
/* Copyright (C) 2026 Facilo <info@facilo.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/dir3.php
 * \ingroup facturapublica
 * \brief   DIR3 search for the finder panel. Read only, returns JSON.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1);
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', 1);
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', 1);
}

$res = 0;
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}
require_once dirname(__DIR__).'/lib/facturapublica.lib.php';
require_once dirname(__DIR__).'/class/FpDir3.class.php';

top_httphead('application/json');
if (!isModEnabled('facturapublica') || !$user->hasRight('facturapublica', 'read')) {
	http_response_code(403);
	print json_encode(array('results' => array()));
	exit;
}
$results = FpDir3::search($db, GETPOST('q', 'alphanohtml'), true);
print json_encode(array('results' => array_slice($results, 0, 20)));
