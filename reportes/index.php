<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/reportes/index.php
 * \ingroup sabrooskipos
 * \brief   ReportesVentas: planillas del turno (fecha + terminal) como tablas,
 *          exportables a Excel (.xlsx). Cálculos en lib/sabrooskipos_reportes.lib.php.
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

require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos_reportes.lib.php';

$langs->loadLangs(array("bills", "cashdesk", "sabrooskipos@sabrooskipos"));

if (!isModEnabled('sabrooskipos') || (!$user->admin && !$user->hasRight('sabrooskipos', 'reportes', 'read'))) {
	accessforbidden();
}

$reportes = array(
	'camion' => array('Camión congelados', 'sabrooskiposReporteCamion'),
	'pagomovil' => array('Pago móvil y puntos', 'sabrooskiposReportePagoMovil'),
	'especificaciones' => array('Especificaciones de ventas', 'sabrooskiposReporteEspecificaciones'),
	'inventario' => array('Inventario de ventas', 'sabrooskiposReporteInventario'),
);

$turnos = sabrooskiposReporteTurnos($db);
$reporte = GETPOST('reporte', 'aZ09');
if (!isset($reportes[$reporte])) {
	$reporte = 'especificaciones';
}
$fecha = GETPOST('fecha', 'alphanohtml');
$terminal = GETPOST('terminal', 'aZ09');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
	$fecha = ($turnos ? $turnos[0]['fecha'] : dol_print_date(dol_now(), '%Y-%m-%d'));
	$terminal = ($turnos ? $turnos[0]['terminal'] : '1');
}
if ($terminal === '') {
	$terminal = '1';
}
$action = GETPOST('action', 'aZ09');

$data = sabrooskiposReporteDatos($db, $fecha, $terminal);
$enc = sabrooskiposReporteEncabezado($data);

if ($action == 'excel' || $action == 'excelall') {
	$list = array();
	foreach ($reportes as $k => $def) {
		if ($action == 'excelall' || $k == $reporte) {
			$list[] = call_user_func($def[1], $data);
		}
	}
	$name = 'Sabrooski_'.($action == 'excelall' ? 'Planillas' : $reporte).'_'.$fecha.'_T'.$terminal.'.xlsx';
	sabrooskiposReporteExcel($list, $enc, $name);
	exit;
}

$rep = call_user_func($reportes[$reporte][1], $data);

$title = 'ReportesVentas – '.$reportes[$reporte][0];
llxHeader('<style>
.sbr-head{display:flex;flex-wrap:wrap;gap:6px 22px;margin:6px 0 12px}
.sbr-title{margin:4px 0}
.sbr-table td,.sbr-table th{white-space:nowrap}
.sbr-table td.left{white-space:normal}
.sbr-diff{background:#f8cbad;font-weight:bold}
.sbr-filter{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px}
</style>', $title, '', '', 0, 0, '', '', '', 'mod-sabrooskipos page-reportes');

$self = $_SERVER['PHP_SELF'];
$qs = '&fecha='.urlencode($fecha).'&terminal='.urlencode($terminal);

$head = array();
$h = 0;
foreach ($reportes as $k => $def) {
	$head[$h][0] = $self.'?reporte='.$k.$qs;
	$head[$h][1] = $def[0];
	$head[$h][2] = $k;
	$h++;
}
print load_fiche_titre('ReportesVentas', '', 'bill');
print dol_get_fiche_head($head, $reporte, '', -1);

// Filtro: turno conocido o fecha + terminal libres.
print '<form method="GET" action="'.$self.'" class="sbr-filter">';
print '<input type="hidden" name="reporte" value="'.dol_escape_htmltag($reporte).'">';
if ($turnos) {
	print '<select name="turno" class="flat minwidth300" onchange="var v=this.value.split(\'|\');this.form.fecha.value=v[0];this.form.terminal.value=v[1];this.form.submit();">';
	print '<option value="">— Turnos con ventas —</option>';
	foreach ($turnos as $t) {
		$sel = ($t['fecha'] == $fecha && $t['terminal'] == $terminal) ? ' selected' : '';
		print '<option value="'.dol_escape_htmltag($t['fecha'].'|'.$t['terminal']).'"'.$sel.'>'.dol_escape_htmltag($t['label']).'</option>';
	}
	print '</select>';
}
print 'Fecha <input type="date" name="fecha" value="'.dol_escape_htmltag($fecha).'">';
print 'Terminal <select name="terminal" class="flat">';
for ($t = 1; $t <= max(1, getDolGlobalInt('TAKEPOS_NUM_TERMINALS', 1)); $t++) {
	print '<option value="'.$t.'"'.($t == $terminal ? ' selected' : '').'>'.dol_escape_htmltag(getDolGlobalString('TAKEPOS_TERMINAL_NAME_'.$t, 'Terminal '.$t)).'</option>';
}
print '</select>';
print '<input type="submit" class="button smallpaddingimp" value="Ver">';
print '<a class="button smallpaddingimp" href="'.$self.'?reporte='.$reporte.$qs.'&action=excel">'.img_picto('', 'file-excel', 'class="pictofixedwidth"').'Exportar a Excel</a>';
print '<a class="button smallpaddingimp" href="'.$self.'?reporte='.$reporte.$qs.'&action=excelall">'.img_picto('', 'file-excel', 'class="pictofixedwidth"').'Exportar las 4 planillas</a>';
print '</form>';

if (empty($data['tickets'])) {
	print '<div class="warning">No hay tickets TakePOS validados para esa fecha y terminal.</div>';
}
if ($data['almacen'] <= 0) {
	print '<div class="warning">El terminal no tiene almacén configurado (CASHDESK_ID_WAREHOUSE'.dol_escape_htmltag($terminal).'): el stock sale vacío.</div>';
}

print sabrooskiposReporteHtml($rep, $enc);

print dol_get_fiche_end();
llxFooter();
$db->close();
