<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/picker/index.php
 * \ingroup sabrooskipos
 * \brief   Popup "Toma de orden" (se abre dentro de TakePOS vía colorbox).
 *
 * Recibe place, invoiceid y token desde el botón de TakePOS (hook ActionButtons).
 * Solo sirve el contenedor del modal: las categorías y los productos los entrega
 * picker/api.php (misma fuente de datos que el grid nativo del TakePOS).
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

$langs->loadLangs(array("sabrooskipos@sabrooskipos", "cashdesk", "bills"));

// Security check: solo se exige el permiso del TakePOS (el operador del punto de
// venta ya lo tiene). No se exige un permiso propio del módulo para no bloquear.
if (! $user->hasRight('takepos', 'run')) {
	accessforbidden('No permission to use the TakePOS');
}

$place = GETPOST('place', 'alpha');
$invoiceid = GETPOSTINT('invoiceid');
$token = GETPOST('token', 'alpha');

// Rutas RELATIVAS a DOL_URL_ROOT (sin el host ni el directorio raíz):
// top_htmlhead las pasa por dol_buildpath (que antepone DOL_URL_ROOT y el prefijo
// /custom si el archivo vive en custom/). NUNCA pasar DOL_URL_ROOT delante.
$arrayofcss = array(
	'/sabrooskipos/css/picker.css?v=' . (defined('DOL_VERSION') ? DOL_VERSION : '1') . '-' . @filemtime(DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/css/picker.css')
);

// Terminal / cliente por defecto (lo usa el endpoint nativo getProducts para el precio)
$term = empty($_SESSION['takeposterminal']) ? 1 : $_SESSION['takeposterminal'];
$socid = getDolGlobalInt('CASHDESK_ID_THIRDPARTY'.$term);

$head = '<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>';

// picker.js se carga al final del body (NO en el head), para que window.PICKER ya exista.
// Añadimos un parámetro de versión (?v=...) para forzar al navegador a descargar la
// copia más reciente y evitar que sirva una versión en caché tras cada cambio.
$pickerJsCacheBuster = (defined('DOL_VERSION') ? DOL_VERSION : '1') . '-' . @filemtime(DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/js/picker.js');
top_htmlhead($head, $langs->trans('TomaDeOrden'), 0, 0, array(), $arrayofcss);
?>
<script>
window.PICKER = {
	base: <?php echo json_encode(DOL_URL_ROOT); ?>,
	api: <?php echo json_encode(DOL_URL_ROOT.'/custom/sabrooskipos/picker/api.php'); ?>,
	place: <?php echo json_encode($place); ?>,
	invoiceid: <?php echo (int) $invoiceid; ?>,
	token: <?php echo json_encode($token); ?>,
	socid: <?php echo json_encode($socid); ?>
};
</script>
<body class="sabrooski-picker">

<div id="picker-root" class="picker-root">
	<div class="pk-loading">Cargando…</div>
</div>

<!-- Cargado al final del body: aquí window.PICKER ya existe (script de arriba).
     picker.js pide categorías y productos a picker/api.php y los pinta aquí. -->
<script src="<?php echo DOL_URL_ROOT; ?>/custom/sabrooskipos/js/picker.js?v=<?php echo $pickerJsCacheBuster; ?>"></script>

<?php
llxFooter();
$db->close();
