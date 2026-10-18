<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/admin/migracion_export.php
 * \ingroup sabrooskipos
 * \brief   TEMPORAL: descarga el paquete de migración completo (catálogo, configuración
 *          del POS y del popup, y los turnos del rango: tickets, pagos, movimientos
 *          bancarios y de stock). Se importa en otra instalación con migracion_import.php.
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
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
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

require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos_migracion.lib.php';

$langs->loadLangs(array("admin", "products", "sabrooskipos@sabrooskipos"));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$datefrom = GETPOST('datefrom', 'alphanohtml') ?: '2026-10-10';
$dateto = GETPOST('dateto', 'alphanohtml') ?: $datefrom;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datefrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateto)) {
	$datefrom = $dateto = '2026-10-10';
}

$data = sabrooskiposExportPaquete($db, $datefrom, $dateto);

if ($action == 'download') {
	$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	$filename = 'sabrooskipos_paquete_'.str_replace('-', '', $datefrom).'_'.str_replace('-', '', $dateto).'.json';
	header('Content-Type: application/json; charset=utf-8');
	header('Content-Disposition: attachment; filename="'.$filename.'"');
	header('Content-Length: '.strlen($json));
	echo $json;
	exit;
}

$title = 'Exportar paquete de migración (temporal)';
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-sabrooskipos page-admin');
print load_fiche_titre($title, '', 'title_setup');

print '<div class="opacitymedium marginbottom">Genera un JSON con <b>todo</b>: catálogo (categorías, productos, precios, extrafields, retirados), '
	.'configuración del POS y del popup (terminales, almacenes, cuentas, formas de pago, categorías del popup) y los <b>turnos</b> del rango '
	.'(tickets con líneas, pagos, movimientos bancarios y de stock, con su fecha y hora). Se importa en producción con <b>migracion_import.php</b>.</div>';

print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">Rango de turnos</td></tr>';
print '<tr class="oddeven"><td class="titlefield">Desde</td><td><input type="date" name="datefrom" value="'.dol_escape_htmltag($datefrom).'"></td></tr>';
print '<tr class="oddeven"><td>Hasta</td><td><input type="date" name="dateto" value="'.dol_escape_htmltag($dateto).'"> <input type="submit" class="button smallpaddingimp" value="Actualizar"></td></tr>';
print '</table></form><br>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Contenido</td><td class="right">Cantidad</td></tr>';
print '<tr class="oddeven"><td>Categorías de producto</td><td class="right">'.count($data['catalogo']['categories']).'</td></tr>';
print '<tr class="oddeven"><td>Productos</td><td class="right">'.count($data['catalogo']['products']).'</td></tr>';
print '<tr class="oddeven"><td>Productos a retirar en destino</td><td class="right">'.count($data['catalogo']['retired_refs']).'</td></tr>';
print '<tr class="oddeven"><td>Constantes de configuración</td><td class="right">'.count($data['config']['constants']).'</td></tr>';
print '<tr class="oddeven"><td>Cuentas / almacenes / formas de pago</td><td class="right">'.count($data['config']['bank_accounts']).' / '.count($data['config']['warehouses']).' / '.count($data['config']['payment_modes']).'</td></tr>';
foreach ($data['turnos'] as $t) {
	$tot = 0;
	$np = 0;
	foreach ($t['tickets'] as $tk) {
		$tot += $tk['total_ttc'];
		$np += count($tk['pagos']);
	}
	print '<tr class="oddeven"><td>Turno '.dol_escape_htmltag($t['fecha']).' · terminal '.dol_escape_htmltag($t['terminal']).'</td><td class="right">'
		.count($t['tickets']).' tickets ('.price($tot, 0, $langs, 1, -1, -1, $conf->currency).'), '.$np.' pagos, '.count($t['stock']).' mov. de stock</td></tr>';
}
if (!count($data['turnos'])) {
	print '<tr class="oddeven"><td colspan="2" class="opacitymedium">No hay tickets TakePOS validados en el rango.</td></tr>';
}
print '</table><br>';

print '<div class="center"><a class="button" href="'.$_SERVER["PHP_SELF"].'?action=download&datefrom='.urlencode($datefrom).'&dateto='.urlencode($dateto).'">Descargar paquete JSON</a></div>';
print '<br><div class="opacitymedium">Archivo temporal de migración. Eliminá estas páginas cuando termines las pruebas.</div>';

llxFooter();
$db->close();
