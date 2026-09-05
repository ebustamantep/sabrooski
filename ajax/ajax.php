<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/ajax/ajax.php
 * \ingroup sabrooskipos
 * \brief   Endpoints JSON del POS Sabrooski.
 *
 *   - action=getModalData : devuelve el producto (con sus extrafields
 *     max_sabores / max_toppings_incluidos / max_sirope) y las listas de
 *     sabores, toppings y siropes definidas en sus categorías.
 *   - action=addItem : agrega la línea del producto base (con la descripción
 *     armada desde la selección) y, si eligió adicionales, agrega cada
 *     adicional como una línea de venta propia (producto real Dolibarr).
 *
 * La factura provisional que se usa es la misma del TakePOS
 * (ref '(PROV-POS<term>-<place>)'), así el carrito se sigue leyendo
 * con takepos/invoice.php y el cobro con takepos/pay.php.
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

// Load translation files required by the page
$langs->loadLangs(array("sabrooskipos@sabrooskipos", "cashdesk", "bills", "products"));

// Needed classes
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos.lib.php';

// Security check
if (! $user->hasRight('takepos', 'run')) {
	accessforbidden('No permission to use the TakePOS');
}
if (! $user->hasRight('sabrooskipos', 'use', 'use')) {
	accessforbidden('No permission to use the Sabrooski POS');
}

$action = GETPOST('action', 'aZ09');
$idproduct = GETPOSTINT('idproduct');
$place = (GETPOST('place', 'aZ09') ? GETPOST('place', 'aZ09') : 0); // Same "place" as TakePOS (table id)

// Terminal stored into session by the index page
if (empty($_SESSION["takeposterminal"])) {
	$_SESSION["takeposterminal"] = 1;
}
$term = $_SESSION["takeposterminal"];

$constantforcompanyid = 'CASHDESK_ID_THIRDPARTY'.$term;
$sociddefault = getDolGlobalInt($constantforcompanyid);

/**
 * Return the provisional invoice of the current terminal/place.
 *
 * @param bool $create If true, create the invoice when it does not exist yet.
 *
 * @return Facture|int 0 when not found and $create is false
 */
function getProvisionalInvoice($db, $sociddefault, $term, $place, $create = true)
{
	global $conf, $user;

	$invoice = new Facture($db);

	// Try to fetch existing provisional invoice by its ref
	$ret = $invoice->fetch(0, '(PROV-POS'.$term.'-'.$place.')');

	if ($ret <= 0) {
		if (!$create) {
			return 0;
		}

		// Create a new provisional invoice
		$invoice->socid = $sociddefault;
		$invoice->date = dol_mktime(0, 0, 0, (int) dol_print_date(dol_now('tzuserrel'), '%m', 'gmt'), (int) dol_print_date(dol_now('tzuserrel'), '%d', 'gmt'), (int) dol_print_date(dol_now('tzuserrel'), '%Y', 'gmt'), 'tzserver');
		$invoice->module_source = 'takepos';
		$invoice->pos_source = $term;
		$invoice->entity = !empty($_SESSION["takeposinvoiceentity"]) ? $_SESSION["takeposinvoiceentity"] : $conf->entity;

		if ($invoice->socid <= 0) {
			return 0;
		}

		$db->begin();
		$placeid = $invoice->create($user);
		if ($placeid < 0) {
			$db->rollback();
			return 0;
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX."facture";
		$sql .= " SET ref='(PROV-POS".$db->escape($term)."-".$db->escape($place).")'";
		$sql .= " WHERE rowid = ".((int) $placeid);
		$resql = $db->query($sql);
		if (!$resql) {
			$db->rollback();
			return 0;
		}
		$db->commit();

		$invoice->fetch($placeid);
	}

	return $invoice;
}

/**
 * Sanitize an array of strings (labels) for the description: trim, strip tags,
 * limit length and count.
 *
 * @return array<int,string>
 */
function sanitizeLabels($arr)
{
	if (!is_array($arr)) {
		return array();
	}
	$out = array();
	foreach ($arr as $item) {
		$s = dol_string_nohtmltag(trim((string) $item));
		if ($s !== '' && dol_strlen($s) <= 80) {
			$out[] = $s;
		}
		if (count($out) >= 20) {
			break;
		}
	}
	return $out;
}

/**
 * Build the human readable description from the selection.
 *
 * @return string
 */
function buildLineDescription($flavors, $toppings, $syrups)
{
	$parts = array();

	if (is_array($flavors) && count($flavors)) {
		$parts[] = "Sabor: ".implode(', ', $flavors);
	}
	if (is_array($toppings) && count($toppings)) {
		$parts[] = "Topping: ".implode(', ', $toppings);
	}
	if (is_array($syrups) && count($syrups)) {
		$parts[] = "Sirope: ".implode(', ', $syrups);
	}

	return implode(" · ", $parts);
}

/**
 * Add a product line to an invoice, replicating the price/VAT logic of TakePOS.
 *
 * @param Facture $invoice
 * @param int     $fkproduct
 * @param int     $qty
 * @param string  $desc        Optional description override
 *
 * @return int line id (>0) or 0 on failure
 */
function addProductLine($db, $invoice, $fkproduct, $qty, $desc = '')
{
	global $conf, $user, $mysoc;

	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

	$prod = new Product($db);
	$prod->fetch($fkproduct);

	$customer = new Societe($db);
	$customer->fetch($invoice->socid);

	$datapriceofproduct = $prod->getSellPrice($mysoc, $customer, 0);

	$price = $datapriceofproduct['pu_ht'];
	$price_ttc = $datapriceofproduct['pu_ttc'];
	$price_base_type = empty($datapriceofproduct['price_base_type']) ? 'HT' : $datapriceofproduct['price_base_type'];
	$tva_tx = $datapriceofproduct['tva_tx'];
	$tva_npr = (int) $datapriceofproduct['tva_npr'];

	// Local taxes
	$localtax1_tx = get_localtax($tva_tx, 1, $customer, $mysoc, $tva_npr);
	$localtax2_tx = get_localtax($tva_tx, 2, $customer, $mysoc, $tva_npr);

	$remise_percent = $customer->remise_percent;

	$array_options = array();

	$line_description = ($desc !== '' ? $desc : $prod->label);

	$idoflineadded = $invoice->addline(
		$line_description,
		$price,
		$qty,
		$tva_tx,
		$localtax1_tx,
		$localtax2_tx,
		$fkproduct,
		$remise_percent,
		'',        // date_start
		0,         // date_end
		0,         // fk_code_ventilation
		0,         // info_bits
		0,         // fk_remise_except
		$price_base_type,
		$price_ttc,
		$prod->type,
		-1,        // rang
		0,         // special_code
		'',        // origin
		0,         // origin_id
		0,         // fk_parent_line
		0,         // fk_fournprice
		0,         // pa_ht
		'',        // label
		$array_options,
		100,       // situation_percent
		0,         // fk_prev_id
		null,      // fk_unit
		0,         // pu_ht_devise
		''         // ref_ext
	);

	return ($idoflineadded > 0 ? $idoflineadded : 0);
}

/**
 * Fetch products of a category as simple array {id, label, ref, price_ttc_formated}.
 *
 * @return array
 */
function getCategoryProducts($db, $catid)
{
	global $langs, $conf;

	if (empty($catid)) {
		return array();
	}

	require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

	$object = new Categorie($db);
	$result = $object->fetch($catid);
	if ($result <= 0) {
		return array();
	}

	$prods = $object->getObjectsInCateg("product", 0, 0, 0, getDolGlobalString('TAKEPOS_SORTPRODUCTFIELD', 'label'), 'ASC', '(o.tosell:=:1)');

	$res = array();
	if (is_array($prods)) {
		foreach ($prods as $prod) {
			if (empty($prod->tosell)) {
				continue;
			}
			$entry = array(
				'id' => (int) $prod->id,
				'label' => $prod->label,
				'ref' => $prod->ref,
			);
			$res[] = $entry;
		}
	}

	return $res;
}

top_httphead('application/json');

if ($action == 'getModalData') {
	// --- Producto base con extrafields ---
	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

	$prod = new Product($db);
	if ($idproduct <= 0 || $prod->fetch($idproduct) <= 0) {
		echo json_encode(array('error' => 'Product not found'));
		exit;
	}

	$maxSabores = (isset($prod->array_options['options_max_sabores']) ? (int) $prod->array_options['options_max_sabores'] : 2);
	$maxToppings = (isset($prod->array_options['options_max_toppings_incluidos']) ? (int) $prod->array_options['options_max_toppings_incluidos'] : 0);
	$maxSirope = (isset($prod->array_options['options_max_sirope']) ? (int) $prod->array_options['options_max_sirope'] : 1);

	// Precio de venta (TTC formateado + numérico) para mostra-lo en el modal
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
	$customer = new Societe($db);
	if ($sociddefault > 0) {
		$customer->fetch($sociddefault);
	}
	$datapriceofproduct = $prod->getSellPrice($mysoc, $customer, 0);
	$price_ttc = empty($datapriceofproduct['pu_ttc']) ? 0 : (float) $datapriceofproduct['pu_ttc'];
	$price_ttc_formated = price($price_ttc, 1, $langs, 1, -1, -1, $conf->currency);

	$product = array(
		'id' => (int) $prod->id,
		'label' => $prod->label,
		'ref' => $prod->ref,
		'description' => dol_string_nohtmltag($prod->description),
		'price' => $price_ttc,
		'price_ttc_formated' => $price_ttc_formated,
		'max_sabores' => $maxSabores,
		'max_toppings_incluidos' => $maxToppings,
		'max_sirope' => $maxSirope,
	);

	// --- Listas de sabores / toppings / siropes ---
	$flavors = getCategoryProducts($db, (int) sabrooskiposGetConst('SABROOSKIPOS_CATEGORY_FLAVORS', $term));
	$toppings = getCategoryProducts($db, (int) sabrooskiposGetConst('SABROOSKIPOS_CATEGORY_TOPPINGS', $term));
	$syrups = getCategoryProducts($db, (int) sabrooskiposGetConst('SABROOSKIPOS_CATEGORY_SYRUPS', $term));

	echo json_encode(array(
		'product' => $product,
		'flavors' => $flavors,
		'toppings' => $toppings,
		'syrups' => $syrups,
	));
	exit;
}

if ($action == 'getCartData') {
	$invoice = getProvisionalInvoice($db, $sociddefault, $term, $place, false);
	if (!is_object($invoice)) {
		echo json_encode(array('invoiceid' => 0, 'lines' => array(), 'total' => 0, 'total_formated' => ''));
		exit;
	}

	$lines = array();
	if (is_array($invoice->lines) && count($invoice->lines)) {
		// Show most recent first, like the TakePOS cart (lines are reversed)
		foreach (array_reverse($invoice->lines) as $line) {
			$lines[] = array(
				'id' => (int) $line->id,
				'ref' => $line->product_ref,
				'label' => $line->product_label,
				'desc' => $line->desc,
				'qty' => (float) $line->qty,
				'total_ttc' => $line->total_ttc,
				'total_ttc_formated' => price($line->total_ttc, 1, $langs, 1, -1, -1, $conf->currency),
			);
		}
	}

	$total_ttc = (float) $invoice->total_ttc;

	echo json_encode(array(
		'invoiceid' => (int) $invoice->id,
		'lines' => $lines,
		'total' => $total_ttc,
		'total_formated' => price($total_ttc, 1, $langs, 1, -1, -1, $conf->currency),
		'currency' => $conf->currency,
	));
	exit;
}

if ($action == 'addItem') {
	$qty = GETPOSTISSET('qty') ? GETPOSTFLOAT('qty') : 1;
	if ($qty <= 0) {
		$qty = 1;
	}

	// Selection as JSON arrays (labels for the description)
	$flavors = sanitizeLabels(json_decode(GETPOST('flavors', 'none'), true));
	$toppings = sanitizeLabels(json_decode(GETPOST('toppings', 'none'), true));
	$syrups = sanitizeLabels(json_decode(GETPOST('syrups', 'none'), true));

	// Additional units: array of {idproduct, qty}
	$extras = json_decode(GETPOST('extras', 'none'), true);
	if (!is_array($extras) || count($extras) > 50) {
		$extras = array();
	}

	if ($idproduct <= 0) {
		echo json_encode(array('error' => 'Product not found'));
		exit;
	}

	// Check at least one flavor selected (a helado is defined by its sabor)
	if (count($flavors) === 0) {
		echo json_encode(array('error' => 'Selecciona al menos un sabor'));
		exit;
	}

	$invoice = getProvisionalInvoice($db, $sociddefault, $term, $place);
	if (!is_object($invoice)) {
		echo json_encode(array('error' => 'No se pudo crear la factura provisional (falta socio por defecto en TakePOS)'));
		exit;
	}

	$db->begin();

	$error = 0;
	$lineid = 0;

	// 1) Línea principal con la descripción personalizada
	$desc = buildLineDescription($flavors, $toppings, $syrups);
	$lineid = addProductLine($db, $invoice, $idproduct, $qty, $desc);
	if ($lineid <= 0) {
		$error++;
	}

	// 2) Adicionales: cada unidad como línea de venta propia
	if (!$error && count($extras)) {
		foreach ($extras as $extra) {
			$extrapid = (int) $extra['idproduct'];
			$extraqty = (int) $extra['qty'];
			if ($extrapid > 0 && $extraqty > 0) {
				$resextra = addProductLine($db, $invoice, $extrapid, $extraqty);
				if ($resextra <= 0) {
					$error++;
					break;
				}
			}
		}
	}

	if ($error) {
		$db->rollback();
		echo json_encode(array('error' => 'No se pudo agregar una o más líneas'));
		exit;
	}

	$invoice->fetch($invoice->id);
	$db->commit();

	echo json_encode(array('ok' => 1, 'invoiceid' => $invoice->id, 'lineid' => $lineid));
	exit;
}

if ($action == 'removeLine') {
	// Quitar una línea del carrito (factura provisional). Mismo mecanismo que
	// usa TakePOS para borrar líneas (deleteline), pero por nuestro endpoint.
	$lineid = GETPOSTINT('lineid');
	if ($lineid <= 0) {
		echo json_encode(array('error' => 'Line not found'));
		exit;
	}

	$invoice = getProvisionalInvoice($db, $sociddefault, $term, $place, false);
	if (!is_object($invoice) || $invoice->id <= 0) {
		echo json_encode(array('error' => 'No invoice'));
		exit;
	}

	$db->begin();
	$res = $invoice->deleteLine($lineid);
	if ($res < 0) {
		$db->rollback();
		echo json_encode(array('error' => 'No se pudo quitar la línea'));
		exit;
	}
	$db->commit();

	$invoice->fetch($invoice->id);

	echo json_encode(array('ok' => 1, 'invoiceid' => $invoice->id));
	exit;
}

echo json_encode(array('error' => 'Unknown action'));
