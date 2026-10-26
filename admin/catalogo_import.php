<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/admin/catalogo_import.php
 * \ingroup sabrooskipos
 * \brief   TEMPORAL: importa un JSON exportado por catalogo_export.php,
 *          recreando categorías y productos por LABEL/REF (independiente de IDs)
 *          y fijando las constantes del módulo. Idempotente.
 *
 * Uso: subir el archivo JSON (o dejarlo como custom/sabrooskipos/catalogo_export.json).
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

require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos.lib.php';

$langs->loadLangs(array("admin", "products"));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

$report = array(
	'categorias_creadas' => array(),
	'productos_creados' => array(),
	'productos_existentes' => array(),
	'errores' => array(),
	'notas' => array(),
);
$sourceinfo = '';

/*
 * Acción: importar
 */
if ($action == 'run' && $user->admin) {
	$data = null;

	// 1) Archivo subido
	if (!empty($_FILES['catalogo']['tmp_name']) && is_uploaded_file($_FILES['catalogo']['tmp_name'])) {
		$raw = file_get_contents($_FILES['catalogo']['tmp_name']);
		$data = json_decode($raw, true);
		$sourceinfo = 'archivo subido: '.$_FILES['catalogo']['name'];
	} else {
		// 2) Archivo por defecto en la carpeta del módulo
		$fallback = DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/catalogo_export.json';
		if (is_readable($fallback)) {
			$data = json_decode(file_get_contents($fallback), true);
			$sourceinfo = 'archivo del módulo: '.$fallback;
		}
	}

	if (!is_array($data)) {
		setEventMessages('No se recibió un JSON válido (subí el archivo o dejá catalogo_export.json en el módulo).', null, 'errors');
	} else {
		// Extrafields antes de la transacción (ALTER TABLE = commit implícito en MySQL).
		sabrooskiposImportExtrafields($db, $data, $report);
		$db->begin();
		$result = sabrooskiposImportCatalogo($db, $user, $data, $report);
		if ($result > 0) {
			$db->commit();
			setEventMessages('Catálogo importado.', null, 'mesgs');
		} else {
			$db->rollback();
			setEventMessages('El JSON no contiene categorías ni productos.', null, 'errors');
		}
	}
}

/*
 * View
 */
$title = "Importar catálogo (temporal)";

llxHeader('', $langs->trans($title), '', '', 0, 0, '', '', '', 'mod-sabrooskipos page-admin');

print load_fiche_titre($title, '', 'title_setup');

print '<div class="opacitymedium marginbottom">';
print 'Importa un JSON generado por <b>catalogo_export.php</b>. Recrea categorías y productos por <b>label/ref</b> (los IDs se resuelven solos) y fija las constantes. Es idempotente.';
print '</div>';

// Reporte
if ($sourceinfo !== '' || !empty($report['categorias_creadas']) || !empty($report['productos_creados']) || !empty($report['productos_existentes']) || !empty($report['errores']) || !empty($report['notas'])) {
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';

	if ($sourceinfo !== '') {
		print '<tr class="liste_titre"><td>Origen</td></tr>';
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($sourceinfo).'</td></tr>';
	}
	if (!empty($report['categorias_creadas'])) {
		print '<tr class="liste_titre"><td>'.count($report['categorias_creadas']).' categorías creadas</td></tr>';
		print '<tr class="oddeven"><td>'.dol_escape_htmltag(implode(', ', $report['categorias_creadas'])).'</td></tr>';
	}
	if (!empty($report['productos_creados'])) {
		print '<tr class="liste_titre"><td>'.count($report['productos_creados']).' productos creados</td></tr>';
		print '<tr class="oddeven"><td>'.dol_escape_htmltag(implode(', ', $report['productos_creados'])).'</td></tr>';
	}
	if (!empty($report['productos_existentes'])) {
		print '<tr class="liste_titre"><td>'.count($report['productos_existentes']).' productos ya existentes</td></tr>';
		print '<tr class="oddeven"><td>'.dol_escape_htmltag(implode(', ', $report['productos_existentes'])).'</td></tr>';
	}
	if (!empty($report['notas'])) {
		print '<tr class="liste_titre"><td>Constantes fijadas</td></tr>';
		print '<tr class="oddeven"><td>'.dol_escape_htmltag(implode(' | ', $report['notas'])).'</td></tr>';
	}
	if (!empty($report['errores'])) {
		print '<tr class="liste_titre"><td>Errores</td></tr>';
		print '<tr class="oddeven"><td class="error">'.dol_escape_htmltag(implode(' | ', $report['errores'])).'</td></tr>';
	}

	print '</table>';
	print '</div><br>';
}

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="run">';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="oddeven"><td class="titlefield">Archivo JSON</td><td><input type="file" name="catalogo" accept=".json,application/json"></td></tr>';
print '<tr class="oddeven"><td>o archivo por defecto</td><td class="opacitymedium">custom/sabrooskipos/catalogo_export.json</td></tr>';
print '</table></div>';
print '<div class="center"><input type="submit" class="button" value="Importar catálogo"></div>';
print '</form>';

print '<br><div class="opacitymedium">Archivo temporal de migración. Eliminá estas páginas cuando termines las pruebas.</div>';

llxFooter();
$db->close();
