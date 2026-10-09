<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/admin/catalogo_export.php
 * \ingroup sabrooskipos
 * \brief   TEMPORAL: exporta categorías de producto + productos (+ categorías,
 *          extrafields) + constantes del módulo a un JSON portable (sin IDs).
 *
 * Pensado para clonar los datos de una instalación (local) a otra (producción)
 * con IDs distintos. El import lo hace admin/catalogo_import.php.
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
require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos.lib.php';

$langs->loadLangs(array("admin", "products"));

if (!$user->admin) {
	accessforbidden();
}

// Datos de export (lógica en la lib, reutilizada por el import y las pruebas)
$data = sabrooskiposExportCatalogo($db);

$action = GETPOST('action', 'aZ09');

/*
 * Acción: descargar JSON
 */
if ($action == 'download' && $user->admin) {
	$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	$filename = 'catalogo_export_'.dol_print_date(dol_now(), '%Y%m%d_%H%M').'.json';
	header('Content-Type: application/json; charset=utf-8');
	header('Content-Disposition: attachment; filename="'.$filename.'"');
	header('Content-Length: '.strlen($json));
	echo $json;
	exit;
}

/*
 * View
 */
$title = "Exportar catálogo (temporal)";

llxHeader('', $langs->trans($title), '', '', 0, 0, '', '', '', 'mod-sabrooskipos page-admin');

print load_fiche_titre($title, '', 'title_setup');

print '<div class="opacitymedium marginbottom">';
print 'Genera un archivo JSON con las <b>categorías de producto</b>, los <b>productos</b> (con sus categorías, precios y extrafields) y las <b>constantes</b> del módulo, ';
print 'usando <b>labels/refs</b> (sin IDs). Luego importalo en producción con <b>catalogo_import.php</b>.';
print '</div>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Resumen</td><td class="right">Cantidad</td></tr>';
print '<tr class="oddeven"><td>Categorías de producto</td><td class="right">'.count($data['categories']).'</td></tr>';
print '<tr class="oddeven"><td>Productos (en categorías)</td><td class="right">'.count($data['products']).'</td></tr>';
print '<tr class="oddeven"><td>Raíz TakePOS (label)</td><td class="right">'.dol_escape_htmltag($data['root_category_label']).'</td></tr>';
print '<tr class="oddeven"><td>Constantes por categoría</td><td class="right">'.count($data['constants_by_category_label']).'</td></tr>';
print '<tr class="oddeven"><td>Constantes por producto</td><td class="right">'.count($data['constants_by_product_ref']).'</td></tr>';
print '<tr class="oddeven"><td>Categorías ocultas</td><td class="right">'.count($data['hidden_category_labels']).'</td></tr>';
print '</table><br>';

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="download">';
print '<div class="center"><input type="submit" class="button" value="Descargar JSON"></div>';
print '</form>';

print '<br><div class="opacitymedium">Archivo temporal de migración. Eliminá estas páginas (<b>catalogo_export.php</b> / <b>catalogo_import.php</b>) cuando termines las pruebas.</div>';

llxFooter();
$db->close();
