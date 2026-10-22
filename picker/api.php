<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/picker/api.php
 * \ingroup sabrooskipos
 * \brief   Endpoints JSON para el popup "Toma de orden".
 *
 *   - action=getData : categorías reales (hijas de la raíz de TakePOS) y las listas
 *                      de sabores / toppings / siropes. NO devuelve los productos
 *                      base: esos los pide el JS al endpoint nativo del TakePOS
 *                      (takepos/ajax/ajax.php?action=getProducts), el mismo que pinta
 *                      la rejilla nativa.
 *   - action=getProduct : datos de un producto (extrafields max_* + precio) para el modal.
 *   - action=getInvoice  : id de la factura provisional por la ref, para anotar el detalle.
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
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

$langs->loadLangs(array("sabrooskipos@sabrooskipos", "cashdesk", "bills", "products"));

require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos.lib.php';

// Security check: solo el permiso del TakePOS.
if (! $user->hasRight('takepos', 'run')) {
	accessforbidden('No permission to use the TakePOS');
}

$action = GETPOST('action', 'aZ09');
$idproduct = GETPOSTINT('idproduct');

// Terminal activo (para leer las configuraciones por terminal).
$term = empty($_SESSION['takeposterminal']) ? 1 : (int) $_SESSION['takeposterminal'];

top_httphead('application/json');

/**
 * Categorías reales de producto (hijas de la raíz de TakePOS), como las lista el
 * grid nativo.
 */
function pickerCategories($db)
{
	$rootid = getDolGlobalInt('TAKEPOS_ROOT_CATEGORY_ID');
	if (empty($rootid)) {
		return array();
	}

	$categorie = new Categorie($db);
	$categories = $categorie->get_full_arbo('product', $rootid, 1);

	$levelofrootcategory = 0;
	foreach ($categories as $key => $categorycursor) {
		if ($categorycursor['id'] == $rootid) {
			$levelofrootcategory = $categorycursor['level'];
			break;
		}
	}
	$levelofmaincategories = $levelofrootcategory + 1;

	// Categorías a ocultar en el popup (IDs separados por coma en
	// SABROOSKIPOS_HIDDEN_CATEGORIES[term]). Vacío = mostrar todas.
	global $term;
	$hiddenCatsStr = sabrooskiposGetConst('SABROOSKIPOS_HIDDEN_CATEGORIES', isset($term) ? $term : 0);
	$hiddenCats = array_filter(array_map('intval', explode(',', (string) $hiddenCatsStr)));

	$res = array();
	foreach ($categories as $key => $categorycursor) {
		if ($categorycursor['level'] == $levelofmaincategories) {
			if (in_array((int) $categorycursor['id'], $hiddenCats)) {
				continue; // categoría oculta (Venta al mayor, etc.)
			}
			$res[] = array('id' => (int) $categorycursor['id'], 'label' => $categorycursor['label']);
		}
	}
	$res = dol_sort_array($res, 'label');

	return $res;
}

/**
 * Lista simple de productos (id, label) de una categoría. Para sabores/toppings/siropes.
 */
function pickerSimpleProducts($db, $catid, $orderby = 'p.label, p.rowid')
{
	if (empty($catid)) {
		return array();
	}

	$pre = MAIN_DB_PREFIX;
	$sql = "SELECT DISTINCT p.rowid, p.ref, p.label
	        FROM ".$pre."categorie_product cp
	        JOIN ".$pre."product p ON p.rowid = cp.fk_product
	        WHERE cp.fk_categorie = ".((int) $catid)."
	        AND p.entity IN (".getEntity('product').")
	        AND p.tosell = 1
	        ORDER BY ".$orderby;
	$resql = $db->query($sql);
	if (!$resql) {
		return array();
	}

	$res = array();
	while ($obj = $db->fetch_object($resql)) {
		$res[] = array('id' => (int) $obj->rowid, 'label' => $obj->label);
	}

	return $res;
}

/**
 * Datos de un producto (id, label, precio) para una fila de "Adicional".
 *
 * @param DoliDB $db
 * @param int    $term   Número de terminal (para el precio por cliente)
 * @param int    $pid    id del producto
 * @param string $label  Etiqueta a mostrar (si vacío, se usa la del producto)
 * @return array|null    {id, label, price_ttc, price_formated} o null
 */
function pickerAdicionalItem($db, $term, $pid, $label = '')
{
	$prod = new Product($db);
	if ($prod->fetch($pid) <= 0) {
		return null;
	}
	if ($label === '') {
		$label = $prod->label;
	}

	$sociddefault = getDolGlobalInt('CASHDESK_ID_THIRDPARTY'.$term);
	$customer = new Societe($db);
	if ($sociddefault > 0) {
		$customer->fetch($sociddefault);
	}

	$dataprice = @$prod->getSellPrice($GLOBALS['mysoc'], $customer, 0);
	$price_ttc = empty($dataprice['pu_ttc']) ? 0 : (float) $dataprice['pu_ttc'];

	return array(
		'id' => (int) $prod->id,
		'label' => $label,
		'price_ttc' => $price_ttc,
		'price_formated' => price($price_ttc, 1, $GLOBALS['langs'], 1, -1, -1, $GLOBALS['conf']->currency),
	);
}

/**
 * Lista de adicionales seleccionables para el terminal.
 *
 * Son los productos genéricos en venta de la categoría Adicional ("Topping" y
 * "Sirope", en orden de ref): el cliente elige el topping/sirope concreto en la
 * barra, así que el popup solo pide la cantidad. Los toppings y siropes reales
 * (categorías Topping/Sirope) son inventario en gramos y no se venden. Si no hay
 * ninguno se usa el producto de respaldo SABROOSKIPOS_PRODUCT_ADICIONAL.
 * Cada producto se suma como una línea aparte con su propio precio.
 *
 * @param DoliDB $db
 * @param int    $term  Número de terminal
 * @return array<array{id:int,label:string,price_ttc:float,price_formated:string}>
 */
function pickerAdicionalOptions($db, $term)
{
	$res = array();

	$catid = (int) sabrooskiposGetConst('SABROOSKIPOS_CATEGORY_ADICIONAL', $term);
	foreach (pickerSimpleProducts($db, $catid, 'p.ref, p.rowid') as $p) {
		$item = pickerAdicionalItem($db, $term, (int) $p['id'], (string) $p['label']);
		if ($item) {
			$res[] = $item;
		}
	}

	if (empty($res)) {
		$pid = (int) sabrooskiposGetConst('SABROOSKIPOS_PRODUCT_ADICIONAL', $term);
		if ($pid > 0) {
			$item = pickerAdicionalItem($db, $term, $pid);
			if ($item) {
				$res[] = $item;
			}
		}
	}

	return $res;
}

if ($action == 'getData') {
	echo json_encode(array(
		'categories' => pickerCategories($db),
		'flavors' => pickerSimpleProducts($db, (int) sabrooskiposGetConst('SABROOSKIPOS_CATEGORY_FLAVORS', $term)),
		'flavors_bandejas' => pickerSimpleProducts($db, (int) sabrooskiposGetConst('SABROOSKIPOS_CATEGORY_FLAVORS_BANDEJAS', $term)),
		'adicionals' => pickerAdicionalOptions($db, $term),
	));
	exit;
}

if ($action == 'getInvoice') {
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	$term = empty($_SESSION['takeposterminal']) ? 1 : $_SESSION['takeposterminal'];
	$place = (GETPOST('place', 'aZ09') ? GETPOST('place', 'aZ09') : 0);
	$invoice = new Facture($db);
	$ret = $invoice->fetch(0, '(PROV-POS'.$term.'-'.$place.')');
	echo json_encode(array('invoiceid' => ($ret > 0 ? (int) $invoice->id : 0)));
	exit;
}

if ($action == 'getCart') {
	// Resumen de la orden (carrito): lee la factura provisional del TakePOS,
	// la misma fuente de verdad del ticket nativo. Devuelve líneas + totales.
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	$term = empty($_SESSION['takeposterminal']) ? 1 : $_SESSION['takeposterminal'];
	$place = (GETPOST('place', 'aZ09') ? GETPOST('place', 'aZ09') : 0);

	$invoice = new Facture($db);
	$ret = $invoice->fetch(0, '(PROV-POS'.$term.'-'.$place.')');
	if ($ret <= 0) {
		echo json_encode(array('invoiceid' => 0, 'lines' => array(), 'itemcount' => 0, 'total_ttc' => 0, 'total_formated' => ''));
		exit;
	}

	$lines = array();
	$itemcount = 0;
	if (is_array($invoice->lines) && count($invoice->lines)) {
		// Mostrar los más recientes arriba, como el carrito del TakePOS.
		foreach (array_reverse($invoice->lines) as $line) {
			$lines[] = array(
				'id' => (int) $line->id,
				'label' => $line->product_label,
				'ref' => $line->product_ref,
				'desc' => $line->desc,
				'qty' => (float) $line->qty,
				'total_ttc' => $line->total_ttc,
				'total_ttc_formated' => price($line->total_ttc, 1, $langs, 1, -1, -1, $conf->currency),
			);
			$itemcount += (float) $line->qty;
		}
	}

	$total_ttc = (float) $invoice->total_ttc;

	echo json_encode(array(
		'invoiceid' => (int) $invoice->id,
		'lines' => $lines,
		'itemcount' => $itemcount,
		'total_ttc' => $total_ttc,
		'total_formated' => price($total_ttc, 1, $langs, 1, -1, -1, $conf->currency),
		'currency' => $conf->currency,
	));
	exit;
}

if ($action == 'getProduct') {
	$prod = new Product($db);
	if ($idproduct <= 0 || $prod->fetch($idproduct) <= 0) {
		echo json_encode(array('error' => 'Product not found'));
		exit;
	}
	$prod->fetch_optionals();

	$term = empty($_SESSION['takeposterminal']) ? 1 : $_SESSION['takeposterminal'];
	$sociddefault = getDolGlobalInt('CASHDESK_ID_THIRDPARTY'.$term);
	$customer = new Societe($db);
	if ($sociddefault > 0) {
		$customer->fetch($sociddefault);
	}

	$dataprice = @$prod->getSellPrice($GLOBALS['mysoc'], $customer, 0);
	$price_ttc = empty($dataprice['pu_ttc']) ? 0 : (float) $dataprice['pu_ttc'];

	// Defaults defensivos: si el producto no tiene el extrafield, se asume 1 sabor.
	// Nunca un valor que rompa el modal.
	$maxSabores = isset($prod->array_options['options_max_sabores']) ? (int) $prod->array_options['options_max_sabores'] : 1;
	$grupoSabores = isset($prod->array_options['options_grupo_sabores']) ? (string) $prod->array_options['options_grupo_sabores'] : '';
	$gramosPorcion = isset($prod->array_options['options_gramos_porcion']) ? (string) $prod->array_options['options_gramos_porcion'] : '';

	echo json_encode(array(
		'product' => array(
			'id' => (int) $prod->id,
			'label' => $prod->label,
			'ref' => $prod->ref,
			'description' => dol_string_nohtmltag($prod->description),
			'price_ttc' => $price_ttc,
			'price_formated' => price($price_ttc, 1, $GLOBALS['langs'], 1, -1, -1, $GLOBALS['conf']->currency),
			'max_sabores' => $maxSabores,
			'grupo_sabores' => $grupoSabores,
			'gramos_porcion' => $gramosPorcion,
		),
		'flavors' => pickerSimpleProducts($db, (int) sabrooskiposGetConst('SABROOSKIPOS_CATEGORY_FLAVORS', $term)),
		'flavors_bandejas' => pickerSimpleProducts($db, (int) sabrooskiposGetConst('SABROOSKIPOS_CATEGORY_FLAVORS_BANDEJAS', $term)),
		'adicionals' => pickerAdicionalOptions($db, $term),
	));
	exit;
}

echo json_encode(array('error' => 'Unknown action'));
