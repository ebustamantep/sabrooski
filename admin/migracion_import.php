<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/admin/migracion_import.php
 * \ingroup sabrooskipos
 * \brief   TEMPORAL: importa el paquete de migración de migracion_export.php.
 *          "Simular" hace todo dentro de una transacción y la revierte; "Importar" la
 *          confirma. Todo se resuelve por ref/label/código. No duplica turnos.
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
$report = null;
$warnings = array();
$simular = ($action == 'simular');
$sourceinfo = '';
$tmpfile = DOL_DATA_ROOT.'/sabrooskipos/temp/paquete_'.$user->id.'.json';

if ($action == 'reactivar' && $user->admin) {
	$r = sabrooskiposReactivarModulo($db);
	if (count($r['errores'])) {
		setEventMessages('No se pudo reactivar el módulo.', $r['errores'], 'errors');
	} else {
		setEventMessages('Módulo reactivado: menú ReportesVentas y permisos registrados ('.$r['restaurados'].' permisos de usuarios/grupos conservados).', null, 'mesgs');
	}
}

if (($action == 'simular' || $action == 'importar') && $user->admin) {
	@set_time_limit(600);
	$raw = '';
	if (!empty($_FILES['paquete']['tmp_name']) && is_uploaded_file($_FILES['paquete']['tmp_name'])) {
		$raw = file_get_contents($_FILES['paquete']['tmp_name']);
		$sourceinfo = $_FILES['paquete']['name'];
		dol_mkdir(dirname($tmpfile));
		file_put_contents($tmpfile, $raw); // para poder importar después de simular sin volver a subirlo
	} elseif (is_readable($tmpfile)) {
		$raw = file_get_contents($tmpfile);
		$sourceinfo = 'último archivo subido';
	}
	$data = json_decode($raw, true);
	if (!is_array($data)) {
		setEventMessages('Subí el archivo JSON del paquete.', null, 'errors');
	} else {
		$report = array('errores' => array(), 'notas' => array(), 'config' => array(), 'tickets' => array());
		$antes = sabrooskiposSnapshot($db);
		$db->begin();
		$res = sabrooskiposImportPaquete($db, $user, $data, $report);
		if ($res > 0 && !$simular) {
			$db->commit();
			setEventMessages('Paquete importado.', null, 'mesgs');
			@unlink($tmpfile);
		} else {
			$db->rollback();
			$despues = sabrooskiposSnapshot($db);
			foreach ($antes as $t => $n) {
				if ($despues[$t] != $n) {
					$warnings[] = 'llx_'.$t.' pasó de '.$n.' a '.$despues[$t].' registros aunque se revirtió: revisar.';
				}
			}
			if ($res > 0) {
				setEventMessages('Simulación correcta: no se guardó nada. Ya podés importar.', null, 'mesgs');
			} else {
				setEventMessages('Hay errores: no se guardó nada.', null, 'errors');
			}
		}
	}
}

$title = 'Importar paquete de migración (temporal)';
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-sabrooskipos page-admin');
$head = sabrooskiposAdminPrepareHead();
print dol_get_fiche_head($head, 'migracion', 'Sabrooski POS', -1, 'sabrooskipos@sabrooskipos');

print '<div class="opacitymedium marginbottom">Importa el paquete generado con <a href="'.dol_buildpath('/sabrooskipos/admin/migracion_export.php', 1).'">migracion_export.php</a>: '
	.'catálogo, configuración del POS y del popup, y turnos completos (tickets, pagos, movimientos bancarios y de stock). '
	.'<b>Simulá primero</b>: hace todo y lo revierte, para ver el reporte sin guardar nada. Hacé un respaldo de la base antes de importar.</div>';

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<table class="noborder centpercent">';
print '<tr class="oddeven"><td class="titlefield">Paquete JSON</td><td><input type="file" name="paquete" accept=".json,application/json">';
if (is_readable($tmpfile)) {
	print ' <span class="opacitymedium">(o se usa el último archivo subido)</span>';
}
print '</td></tr></table>';
print '<div class="center">';
print '<button type="submit" class="button" name="action" value="simular">Simular (no guarda)</button> ';
print '<button type="submit" class="button button-save" name="action" value="importar" onclick="return confirm(\'¿Importar el paquete en esta base de datos?\');">Importar</button>';
print '</div></form>';

print '<br><form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<div class="opacitymedium">Después de subir una versión nueva del módulo (menú ReportesVentas, permisos, hooks): </div>';
print '<div class="center"><button type="submit" class="button" name="action" value="reactivar">Reactivar módulo (conserva permisos y configuración)</button></div>';
print '</form>';

if ($report !== null) {
	print '<br>';
	foreach ($warnings as $w) {
		print '<div class="error">'.dol_escape_htmltag($w).'</div>';
	}
	$cat = isset($report['catalogo']) ? $report['catalogo'] : array('categorias_creadas' => array(), 'productos_creados' => array(), 'productos_existentes' => array(), 'notas' => array());
	$sections = array(
		($simular ? 'SIMULACIÓN' : 'IMPORTACIÓN').' – origen' => array($sourceinfo),
		'Errores' => $report['errores'],
		'Catálogo: '.count($cat['categorias_creadas']).' categorías creadas, '.count($cat['productos_creados']).' productos creados, '.count($cat['productos_existentes']).' existentes' => $cat['notas'],
		'Configuración del POS y del popup' => $report['config'],
		'Notas' => $report['notas'],
		'Tickets (origen → destino)' => $report['tickets'],
	);
	print '<table class="noborder centpercent">';
	foreach ($sections as $titleSec => $lines) {
		if (!count($lines)) {
			continue;
		}
		print '<tr class="liste_titre"><td>'.dol_escape_htmltag($titleSec).' ('.count($lines).')</td></tr>';
		print '<tr class="oddeven"><td'.($titleSec == 'Errores' ? ' class="error"' : '').'>'.implode('<br>', array_map('dol_escape_htmltag', $lines)).'</td></tr>';
	}
	print '</table>';
}

print dol_get_fiche_end();
print '<br><div class="opacitymedium">Archivo temporal de migración. Eliminá estas páginas cuando termines las pruebas.</div>';

llxFooter();
$db->close();
