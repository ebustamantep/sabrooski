<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       sabrooskipos/sabrooskiposindex.php
 * \ingroup    sabrooskipos
 * \brief      Pantalla táctil del punto de venta Sabrooski (custom)
 *
 * Usa los endpoints del TakePOS nativo:
 *   - takepos/ajax/ajax.php        (productos por categoría, JSON)
 *   - takepos/invoice.php          (factura provisional: addline/deleteline/delete)
 *   - takepos/pay.php              (cobro)
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
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

// Redirige al POS nativo de TakePOS: este archivo era la pantalla custom que
// ya no se usa como punto de venta (el botón "Toma de orden" vive en TakePOS).
header('Location: '.DOL_URL_ROOT.'/takepos/index.php');
exit;

/*
 * Terminal / cliente / almacén (igual que takepos/index.php)
 */
if (empty($_SESSION["takeposterminal"])) {
	if (getDolGlobalInt('TAKEPOS_NUM_TERMINALS') == 1) {
		$_SESSION["takeposterminal"] = 1;
	} elseif (!empty($_COOKIE["takeposterminal"])) {
		$_SESSION["takeposterminal"] = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_COOKIE["takeposterminal"]);
	}
}
$term = empty($_SESSION['takeposterminal']) ? 1 : $_SESSION['takeposterminal'];
$socid = getDolGlobalInt('CASHDESK_ID_THIRDPARTY' . $term);
$warehouseid = getDolGlobalInt('CASHDESK_ID_WAREHOUSE' . $term);

// Nombre del terminal
$terminalname = getDolGlobalString("TAKEPOS_TERMINAL_NAME_".$term, $langs->trans("TerminalName", $term));

// Cliente por defecto
$clientename = $langs->trans("DefaultPOSThirdLabel"); // "Público general" / default
if ($socid > 0) {
	include_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
	$soc = new Societe($db);
	if ($soc->fetch($socid) > 0) {
		$clientename = $soc->name;
	}
}

/*
 * Categorías (árbol bajo la categoría raíz del TakePOS)
 */
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
$categorie = new Categorie($db);
$categories = $categorie->get_full_arbo('product', getDolGlobalInt('TAKEPOS_ROOT_CATEGORY_ID'), 1);
$levelofrootcategory = 0;
if (getDolGlobalInt('TAKEPOS_ROOT_CATEGORY_ID') > 0) {
	foreach ($categories as $key => $categorycursor) {
		if ($categorycursor['id'] == getDolGlobalInt('TAKEPOS_ROOT_CATEGORY_ID')) {
			$levelofrootcategory = $categorycursor['level'];
			break;
		}
	}
}
$levelofmaincategories = $levelofrootcategory + 1;
$maincategories = array();
foreach ($categories as $key => $categorycursor) {
	if ($categorycursor['level'] == $levelofmaincategories) {
		$maincategories[] = array('rowid' => $categorycursor['id'], 'label' => $categorycursor['label']);
	}
}
$maincategories = dol_sort_array($maincategories, 'label');

/*
 * View
 */
$arrayofjs = array(
	'/includes/jquery/js/jquery.min.js',
	'/takepos/js/jquery.colorbox-min.js',
	'/sabrooskipos/js/sabrooskipos.js'
);
$arrayofcss = array(
	'https://fonts.googleapis.com/css2?family=Baloo+2:wght@500;700;800&family=Nunito+Sans:wght@400;600;700;800&display=swap',
	'/sabrooskipos/css/sabrooskipos.css'
);

$head = '<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>';

top_htmlhead($head, 'Sabrooski POS', 0, 0, $arrayofjs, $arrayofcss);
?>

<script>
window.SABROOSKI_POS = {
	base: <?php echo json_encode(DOL_URL_ROOT); ?>,
	ajaxUrl: <?php echo json_encode(DOL_URL_ROOT.'/custom/sabrooskipos/ajax/ajax.php'); ?>,
	token: <?php echo json_encode(newToken()); ?>,
	socid: <?php echo (int) $socid; ?>,
	terminal: <?php echo (int) $term; ?>,
	terminalname: <?php echo json_encode($terminalname); ?>,
	clientename: <?php echo json_encode($clientename); ?>,
	warehouseid: <?php echo (int) $warehouseid; ?>,
	rootCategoryId: <?php echo (int) getDolGlobalInt('TAKEPOS_ROOT_CATEGORY_ID'); ?>,
	categories: <?php echo json_encode($maincategories); ?>
};
</script>

<body>

<!-- Contenedor oculto que pay.php usa para ejecutar action=valid en el padre
     (parent.$("#poslines").load(...)). No se muestra: solo recibe el HTML. -->
<div id="poslines" style="display:none;"></div>

<div class="topbar">
	<div class="brand">
		<div class="glasses"></div>
		<div>
			<h1 class="display">SABROOSKI</h1>
			<span class="tag">Punto de venta táctil</span>
		</div>
	</div>
	<div class="info-bar">
		<div class="info-chip">
			<label>Terminal</label>
			<select id="terminalSelect">
				<?php
				$nbloop = getDolGlobalInt('TAKEPOS_NUM_TERMINALS');
				for ($i = 1; $i <= $nbloop; $i++) {
					$namei = getDolGlobalString("TAKEPOS_TERMINAL_NAME_".$i, $langs->trans("TerminalName", $i));
					print '<option value="'.$i.'"'.($i == $term ? ' selected' : '').'>'.$namei.'</option>';
				}
				?>
			</select>
		</div>
		<div class="info-chip">
			<label>Cliente</label>
			<select id="clienteSelect">
				<option value=""><?php echo dol_escape_htmltag($clientename); ?></option>
				<option value="new">+ Nuevo cliente…</option>
			</select>
		</div>
		<div class="info-chip">
			<label>Almacén activo</label>
			<span style="font-weight:700;font-size:13px;min-height:28px;display:flex;align-items:center;">
				<?php echo dol_escape_htmltag($langs->trans("Warehouse")); ?> #<?php echo (int) $warehouseid; ?>
			</span>
		</div>
	</div>
</div>

<div class="layout">
	<div>
		<div class="categories" id="categories"></div>
		<div class="grid" id="productGrid"></div>
	</div>

	<div class="cart" id="cart">
		<div class="cart-head">
			<h2 class="display">Orden actual</h2>
			<button id="clearCart">Vaciar</button>
		</div>
		<div class="cart-lines" id="cartLines">
			<div class="empty-cart"><div class="bubble">¡Lo sé! Es difícil elegir</div><div>Toca un producto para armar la orden.</div></div>
		</div>
		<div class="cart-foot">
			<div class="totals-row"><span>Artículos</span><span id="itemCount">0</span></div>
			<div class="totals-row grand"><span>Total</span><span id="totalUsd">$0.00</span></div>
			<button class="pay-btn" id="payBtn" disabled>Cobrar</button>
		</div>
	</div>
</div>

<!-- Barra resumen móvil -->
<div class="cartbar-mobile" id="cartbarMobile">
	<span id="cartbarCount">0 artículos</span>
	<span class="cartbar-total" id="cartbarTotal">$0.00</span>
	<button class="cartbar-pay" id="cartbarPay">Cobrar</button>
</div>

<!-- Modal de selección de sabor / topping / sirope -->
<div class="overlay" id="productOverlay">
	<div class="modal" id="productModal"></div>
</div>

<?php
llxFooter();
$db->close();
