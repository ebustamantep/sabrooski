<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/carga/main_carga.inc.php
 * \ingroup sabrooskipos
 * \brief   Entorno común de las páginas de carga y cierre del camión.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists(__DIR__."/../../../main.inc.php")) {
	$res = @include __DIR__."/../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos_recetas.lib.php';

$langs->loadLangs(array("stocks", "sabrooskipos@sabrooskipos"));

if (!isModEnabled('sabrooskipos') || !isModEnabled('stock')) {
	accessforbidden();
}

/**
 * Almacenes de los móviles (los de cada terminal del TakePOS), con su nombre.
 *
 * @return array<int,string>  [id almacén => "Movil01PM (terminal 2)"]
 */
function sabrooskiposMoviles()
{
	global $db;
	$res = array();
	for ($t = 1; $t <= max(1, getDolGlobalInt('TAKEPOS_NUM_TERMINALS', 1)); $t++) {
		$wh = getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$t);
		if ($wh > 0 && !isset($res[$wh])) {
			$resql = $db->query("SELECT ref FROM ".MAIN_DB_PREFIX."entrepot WHERE rowid = ".$wh);
			$o = $resql ? $db->fetch_object($resql) : null;
			$res[$wh] = ($o ? $o->ref : 'Almacén '.$wh).' (terminal '.$t.')';
		}
	}
	return $res;
}

/**
 * Cantidad con decimales solo si los tiene.
 *
 * @param float $v
 * @return string
 */
function sabrooskiposCant($v)
{
	return price(price2num($v, 'MS'), 0, '', 0, 0, 3);
}

/**
 * CSS común.
 *
 * @return string
 */
function sabrooskiposCargaCss()
{
	return '<style>.sbr-filter{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;margin-bottom:10px}'
		.'.sbr-table td,.sbr-table th{white-space:nowrap}.sbr-table td:first-child{white-space:normal}</style>';
}
