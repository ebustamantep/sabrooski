<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/admin/setup.php
 * \ingroup sabrooskipos
 * \brief   Configuración del POS Sabrooski.
 *
 * Reutiliza las mismas constantes del TakePOS (TAKEPOS_*) para que el
 * POS custom y el nativo compartan configuración.
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
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos.lib.php';

// Translations
$langs->loadLangs(array("admin", "cashdesk", "sabrooskipos@sabrooskipos"));

// Access control
if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$error = 0;

// Terminal que se está configurando. 0 = pestaña "Configuración" (global, params
// generales del TakePOS). >0 = pestaña del terminal (solo la parte Sabrooski:
// sabores/toppings/siropes/ocultas de ese punto de venta).
$terminal = GETPOSTINT('terminal');
if ($terminal < 0) {
	$terminal = 0;
}
$term = $terminal; // alias corto

/*
 * Actions
 */
if ($action == 'set' && $user->admin) {
	$db->begin();

	// Pestaña global (Configuración): solo constantes de parámetros generales.
	if ($terminal == 0) {
		$consts = array(
			'TAKEPOS_ROOT_CATEGORY_ID' => GETPOST('TAKEPOS_ROOT_CATEGORY_ID', 'alpha'),
			'TAKEPOS_NUM_TERMINALS' => GETPOST('TAKEPOS_NUM_TERMINALS', 'alpha'),
			'TAKEPOS_SORTPRODUCTFIELD' => GETPOST('TAKEPOS_SORTPRODUCTFIELD', 'alpha'),
		);
		foreach ($consts as $key => $val) {
			$res = dolibarr_set_const($db, $key, $val, 'chaine', 0, '', $conf->entity);
			if (!($res > 0)) {
				$error++;
			}
		}

		$constsyesno = array(
			'TAKEPOS_GROUP_SAME_PRODUCT',
			'TAKEPOS_DIRECT_PAYMENT',
			'TAKEPOS_SHOW_HT',
			'TAKEPOS_CHANGE_PRICE_HT',
			'TAKEPOS_HIDE_PRODUCT_PRICES',
			'TAKEPOS_HIDE_CATEGORY_IMAGES',
			'TAKEPOS_SHOW_CATEGORY_DESCRIPTION',
		);
		foreach ($constsyesno as $key) {
			$res = dolibarr_set_const($db, $key, GETPOST($key, 'alpha'), 'chaine', 0, '', $conf->entity);
			if (!($res > 0)) {
				$error++;
			}
		}
	} else {
		// Pestaña de un terminal: SOLO la parte Sabrooski (no se repite lo global).
		$conststerm = array(
			'SABROOSKIPOS_CATEGORY_FLAVORS' => GETPOST('SABROOSKIPOS_CATEGORY_FLAVORS', 'alpha'),
			'SABROOSKIPOS_CATEGORY_TOPPINGS' => GETPOST('SABROOSKIPOS_CATEGORY_TOPPINGS', 'alpha'),
			'SABROOSKIPOS_CATEGORY_SYRUPS' => GETPOST('SABROOSKIPOS_CATEGORY_SYRUPS', 'alpha'),
			'SABROOSKIPOS_HIDDEN_CATEGORIES' => GETPOST('SABROOSKIPOS_HIDDEN_CATEGORIES', 'alpha'),
		);
		foreach ($conststerm as $key => $val) {
			$res = dolibarr_set_const($db, $key.$term, $val, 'chaine', 0, '', $conf->entity);
			if (!($res > 0)) {
				$error++;
			}
		}
	}

	if (!$error) {
		$db->commit();
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		$db->rollback();
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}

/*
 * View
 */
$form = new Form($db);
$help_url = '';
$title = "SabrooskiPOSSetup";

llxHeader('', $langs->trans($title), $help_url, '', 0, 0, '', '', '', 'mod-sabrooskipos page-admin');

// Subheader
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';

print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

// Configuration header
if (function_exists('sabrooskiposAdminPrepareHead')) {
	$head = sabrooskiposAdminPrepareHead();
	$activeselectedtab = ($terminal > 0 ? 'terminal'.$terminal : 'settings');
	print dol_get_fiche_head($head, $activeselectedtab, $langs->trans($title), -1, "sabrooskipos@sabrooskipos");
}

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'?terminal='.$terminal.'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="set">';

// En la pestaña de terminal mostramos un subtítulo indicando cuál se configura.
if ($terminal > 0) {
	$terminalName = getDolGlobalString('TAKEPOS_TERMINAL_NAME_'.$terminal, $langs->trans("TerminalName", $terminal));
	print '<div class="opacitymedium marginbottom">'.$langs->trans('ConfiguringFor').': <b>'.$terminalName.'</b></div>';
}

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';

// ---- PESTAÑA GLOBAL (Configuración): solo parámetros generales del TakePOS ----
if ($terminal == 0) {
	print '<tr class="liste_titre"><td class="titlefield">'.$langs->trans("Parameters").'</td><td></td></tr>';

	// Root category for products
	print '<tr class="oddeven"><td>';
	print $form->textwithpicto($langs->trans("RootCategoryForProductsToSell"), $langs->trans("RootCategoryForProductsToSellDesc"));
	print '</td><td>';
	print img_object('', 'category', 'class="paddingright"').$form->select_all_categories(Categorie::TYPE_PRODUCT, getDolGlobalInt('TAKEPOS_ROOT_CATEGORY_ID'), 'TAKEPOS_ROOT_CATEGORY_ID', 64, 0, 0, 0, 'maxwidth500 widthcentpercentminusx');
	print ajax_combobox('TAKEPOS_ROOT_CATEGORY_ID');
	print '</td></tr>';

	// Number of terminals
	print '<tr class="oddeven"><td>';
	print $langs->trans("NumberTerminals");
	print '</td><td>';
	print '<input type="text" name="TAKEPOS_NUM_TERMINALS" value="'.getDolGlobalString('TAKEPOS_NUM_TERMINALS', 1).'">';
	print '</td></tr>';

	// Sort product
	print '<tr class="oddeven"><td>';
	print $langs->trans("SortProductField");
	print '</td><td>';
	$array = array('rowid' => 'ID', 'ref' => 'Ref', 'label' => 'Label', 'datec' => 'DateCreation', 'tms' => 'DateModification');
	print $form->selectarray('TAKEPOS_SORTPRODUCTFIELD', $array, getDolGlobalString('TAKEPOS_SORTPRODUCTFIELD', 'rowid'), 0, 0, 0, '', 1);
	print '</td></tr>';

	// Group same product
	print '<tr class="oddeven"><td>';
	print $langs->trans('TakeposGroupSameProduct');
	print '</td><td>';
	print ajax_constantonoff("TAKEPOS_GROUP_SAME_PRODUCT", array(), $conf->entity, 0, 0, 1, 0);
	print '</td></tr>';

	// Direct payment
	print '<tr class="oddeven"><td>';
	print $langs->trans('DirectPaymentButton');
	print '</td><td>';
	print ajax_constantonoff("TAKEPOS_DIRECT_PAYMENT", array(), $conf->entity, 0, 0, 1, 0);
	print '</td></tr>';

	// Show price without vat
	print '<tr class="oddeven"><td>';
	print $langs->trans('ShowPriceHT');
	print '</td><td>';
	print ajax_constantonoff("TAKEPOS_SHOW_HT", array(), $conf->entity, 0, 0, 1, 0);
	print '</td></tr>';

	// Use price excl. taxes (HT)
	print '<tr class="oddeven"><td>';
	print $langs->trans('UsePriceHT');
	print '</td><td>';
	print ajax_constantonoff("TAKEPOS_CHANGE_PRICE_HT", array(), $conf->entity, 0, 0, 1, 0);
	print '</td></tr>';

	// Hide product prices in grid
	print '<tr class="oddeven"><td>';
	print $langs->trans('TakeposHideProductPrices');
	print '</td><td>';
	print ajax_constantonoff("TAKEPOS_HIDE_PRODUCT_PRICES", array(), $conf->entity, 0, 0, 1, 0);
	print '</td></tr>';

	// Hide category images
	print '<tr class="oddeven"><td>';
	print $langs->trans('TakeposHideCategoryImages');
	print '</td><td>';
	print ajax_constantonoff("TAKEPOS_HIDE_CATEGORY_IMAGES", array(), $conf->entity, 0, 0, 1, 0);
	print '</td></tr>';

	// Show category description
	print '<tr class="oddeven"><td>';
	print $langs->trans('TakeposShowCategoryDescription');
	print '</td><td>';
	print ajax_constantonoff("TAKEPOS_SHOW_CATEGORY_DESCRIPTION", array(), $conf->entity, 0, 0, 1, 0);
	print '</td></tr>';

	print '<tr><td colspan="2" class="opacitymedium">'.$langs->trans("SabrooskiNoteGlobalConfig").'</td></tr>';
} else {
	// ---- PESTAÑA DE TERMINAL: SOLO la sección Sabrooski (sabores/toppings/siropes/ocultas) ----
	print '<tr class="liste_titre"><td class="titlefield">'.$langs->trans("SabrooskiPOSSetup").'</td><td></td></tr>';

	print '<tr class="oddeven"><td>';
	print img_object('', 'category', 'class="paddingright"').$form->textwithpicto($langs->trans("SabrooskiCategoryFlavors"), $langs->trans("SabrooskiCategoryFlavorsDesc"));
	print '</td><td>';
	print $form->select_all_categories(Categorie::TYPE_PRODUCT, (int) sabrooskiposGetConst('SABROOSKIPOS_CATEGORY_FLAVORS', $term), 'SABROOSKIPOS_CATEGORY_FLAVORS', 64, 0, 0, 0, 'maxwidth500 widthcentpercentminusx');
	print ajax_combobox('SABROOSKIPOS_CATEGORY_FLAVORS');
	print '</td></tr>';

	print '<tr class="oddeven"><td>';
	print img_object('', 'category', 'class="paddingright"').$form->textwithpicto($langs->trans("SabrooskiCategoryToppings"), $langs->trans("SabrooskiCategoryToppingsDesc"));
	print '</td><td>';
	print $form->select_all_categories(Categorie::TYPE_PRODUCT, (int) sabrooskiposGetConst('SABROOSKIPOS_CATEGORY_TOPPINGS', $term), 'SABROOSKIPOS_CATEGORY_TOPPINGS', 64, 0, 0, 0, 'maxwidth500 widthcentpercentminusx');
	print ajax_combobox('SABROOSKIPOS_CATEGORY_TOPPINGS');
	print '</td></tr>';

	print '<tr class="oddeven"><td>';
	print img_object('', 'category', 'class="paddingright"').$form->textwithpicto($langs->trans("SabrooskiCategorySyrups"), $langs->trans("SabrooskiCategorySyrupsDesc"));
	print '</td><td>';
	print $form->select_all_categories(Categorie::TYPE_PRODUCT, (int) sabrooskiposGetConst('SABROOSKIPOS_CATEGORY_SYRUPS', $term), 'SABROOSKIPOS_CATEGORY_SYRUPS', 64, 0, 0, 0, 'maxwidth500 widthcentpercentminusx');
	print ajax_combobox('SABROOSKIPOS_CATEGORY_SYRUPS');
	print '</td></tr>';

	print '<tr class="oddeven"><td>';
	print $form->textwithpicto($langs->trans("SabrooskiHiddenCategories"), $langs->trans("SabrooskiHiddenCategoriesDesc"));
	print '</td><td>';
	print '<input type="text" name="SABROOSKIPOS_HIDDEN_CATEGORIES" value="'.dol_escape_htmltag(sabrooskiposGetConst('SABROOSKIPOS_HIDDEN_CATEGORIES', $term)).'" placeholder="Ej: 8 (IDs separados por coma)">';
	print '</td></tr>';
}

print '</table>';
print '</div>';

print '<div class="center">';
print '<input type="submit" class="button" value="'.$langs->trans("Save").'">';
print '</div>';

print '</form>';

print '<br><div class="opacitymedium">'.$langs->trans("ModuleSabrooskiPOSDesc").'</div>';

if (function_exists('sabrooskiposAdminPrepareHead')) {
	print dol_get_fiche_end();
}

llxFooter();
$db->close();
