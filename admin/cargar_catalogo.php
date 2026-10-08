<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/admin/cargar_catalogo.php
 * \ingroup sabrooskipos
 * \brief   Carga (idempotente) del catálogo real de Sabrooski desde
 *          sabrooski_productos.json: categorías, productos, precios y extrafields.
 *
 * Es seguro re-ejecutar: lo que ya existe (por ref/label) no se duplica.
 * La lógica vive en sabrooskiposLoadCatalogo() (lib/sabrooskipos.lib.php) y también
 * la usa el runner CLI test/cargar_catalogo_cli.php.
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

// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos.lib.php';

// Translations
$langs->loadLangs(array("admin", "products", "sabrooskipos@sabrooskipos"));

// Access control
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

/*
 * Actions
 */
if ($action == 'run' && $user->admin) {
	$jsonfile = DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/sabrooski_productos.json';

	$db->begin();
	$result = sabrooskiposLoadCatalogo($db, $user, $jsonfile, $report);
	if ($result > 0) {
		$db->commit();
		setEventMessages('Catálogo cargado.', null, 'mesgs');
	} else {
		$db->rollback();
		setEventMessages('No se pudo leer o decodificar sabrooski_productos.json', null, 'errors');
	}
}

/*
 * View
 */
$help_url = '';
$title = "SabrooskiPOSCargarCatalogo";

llxHeader('', $langs->trans($title), $help_url, '', 0, 0, '', '', '', 'mod-sabrooskipos page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';
print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

if (function_exists('sabrooskiposAdminPrepareHead')) {
	$head = sabrooskiposAdminPrepareHead();
	print dol_get_fiche_head($head, 'catalogo', $langs->trans($title), -1, "sabrooskipos@sabrooskipos");
}

print '<div class="opacitymedium marginbottom">';
print 'Carga categorías, productos, precios y extrafields desde <b>sabrooski_productos.json</b>. Es idempotente: lo que ya existe (por ref) no se duplica.';
print '</div>';

// Report
if (!empty($report['categorias_creadas']) || !empty($report['productos_creados']) || !empty($report['productos_existentes']) || !empty($report['errores']) || !empty($report['notas'])) {
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';

	if (!empty($report['categorias_creadas'])) {
		print '<tr class="liste_titre"><td>'.count($report['categorias_creadas']).' categorías creadas</td></tr>';
		print '<tr class="oddeven"><td>'.dol_escape_htmltag(implode(', ', $report['categorias_creadas'])).'</td></tr>';
	}
	if (!empty($report['productos_creados'])) {
		print '<tr class="liste_titre"><td>'.count($report['productos_creados']).' productos creados</td></tr>';
		print '<tr class="oddeven"><td>'.dol_escape_htmltag(implode(', ', $report['productos_creados'])).'</td></tr>';
	}
	if (!empty($report['productos_existentes'])) {
		print '<tr class="liste_titre"><td>'.count($report['productos_existentes']).' productos ya existentes (sin cambios)</td></tr>';
		print '<tr class="oddeven"><td>'.dol_escape_htmltag(implode(', ', $report['productos_existentes'])).'</td></tr>';
	}
	if (!empty($report['notas'])) {
		print '<tr class="liste_titre"><td>Notas</td></tr>';
		print '<tr class="oddeven"><td>'.dol_escape_htmltag(implode(' | ', $report['notas'])).'</td></tr>';
	}
	if (!empty($report['errores'])) {
		print '<tr class="liste_titre"><td>Errores</td></tr>';
		print '<tr class="oddeven"><td class="error">'.dol_escape_htmltag(implode(' | ', $report['errores'])).'</td></tr>';
	}

	print '</table>';
	print '</div><br>';
}

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="run">';
print '<div class="center">';
print '<input type="submit" class="button" value="Cargar catálogo desde JSON">';
print '</div>';
print '</form>';

print '<br><div class="opacitymedium">'.$langs->trans("ModuleSabrooskiPOSDesc").'</div>';

if (function_exists('sabrooskiposAdminPrepareHead')) {
	print dol_get_fiche_end();
}

llxFooter();
$db->close();
