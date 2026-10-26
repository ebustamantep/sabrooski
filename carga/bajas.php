<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/carga/bajas.php
 * \ingroup sabrooskipos
 * \brief   Regalías y dañadas del turno: las registra el encargado al cierre con el PIN de
 *          quien autoriza. Lógica en lib/sabrooskipos_bajas.lib.php.
 */

require __DIR__.'/main_carga.inc.php';
require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos_reportes.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos_bajas.lib.php';

if (!$user->admin && !$user->hasRight('sabrooskipos', 'carga', 'write')) {
	accessforbidden();
}

$moviles = sabrooskiposMoviles();
$wh = GETPOSTINT('wh');
if (!isset($moviles[$wh])) {
	$wh = (int) key($moviles);
}
$fecha = GETPOST('fecha', 'alphanohtml');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
	$fecha = dol_print_date(dol_now(), '%Y-%m-%d');
}
$tipos = sabrooskiposBajaTipos();
$action = GETPOST('action', 'aZ09');
$self = $_SERVER['PHP_SELF'].'?wh='.$wh.'&fecha='.urlencode($fecha);

/**
 * Valida el PIN con bloqueo tras 5 intentos fallidos (10 minutos, por sesión).
 *
 * @param DoliDB $db
 * @param string $pin
 * @param array  $errors
 * @return User|null
 */
function sabrooskiposBajaPin($db, $pin, &$errors)
{
	$f = isset($_SESSION['sbr_pin_fail']) ? $_SESSION['sbr_pin_fail'] : array(0, 0);
	if ($f[0] >= 5 && dol_now() - $f[1] < 600) {
		$errors[] = 'Demasiados PIN incorrectos. Espera unos minutos.';
		return null;
	}
	$aut = sabrooskiposPinAutorizador($db, $pin);
	if (!$aut) {
		$_SESSION['sbr_pin_fail'] = array(($f[0] >= 5 ? 0 : $f[0]) + 1, dol_now());
		$errors[] = 'PIN incorrecto o sin permiso para autorizar.';
		return null;
	}
	unset($_SESSION['sbr_pin_fail']);
	return $aut;
}

if ($action == 'registrar' || $action == 'anular') {
	$errors = array();
	$aut = sabrooskiposBajaPin($db, GETPOST('pin', 'alphanohtml'), $errors);
	if ($aut) {
		$db->begin();
		if ($action == 'registrar') {
			$r = sabrooskiposBajaRegistrar($db, $user, $aut, $wh, $fecha, GETPOST('tipo', 'aZ09'), GETPOSTINT('fk_product'), price2num(GETPOST('qty', 'alphanohtml')),
				GETPOST('sabores', 'array'), GETPOST('motivo', 'alphanohtml'), $errors);
		} else {
			$r = sabrooskiposBajaAnular($db, $user, $aut, GETPOSTINT('id'), $errors);
		}
		if ($r > 0) {
			$db->commit();
			setEventMessages(($action == 'registrar' ? 'Registrada' : 'Anulada').' (autorizó '.$aut->login.').', null, 'mesgs');
			header('Location: '.$self);
			exit;
		}
		$db->rollback();
	}
	setEventMessages($action == 'registrar' ? 'No se registró.' : 'No se anuló.', $errors, 'errors');
}

$productos = sabrooskiposBajaProductos($db);
$saboresMix = sabrooskiposBajaSaboresMix($db);
$bajas = sabrooskiposBajasDia($db, $wh, $fecha, false);

llxHeader(sabrooskiposCargaCss().'<style>.sbr-form td{padding:4px 8px}.sbr-sabores label{margin-right:12px;white-space:nowrap}.sbr-anulada td{text-decoration:line-through;opacity:.6}</style>',
	'Regalías y dañadas', '', '', 0, 0, '', '', '', 'mod-sabrooskipos page-bajas');
print load_fiche_titre('Regalías y dañadas', '', 'fa-gift');

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" class="sbr-filter">';
print 'Móvil <select name="wh" class="flat" onchange="this.form.submit()">';
foreach ($moviles as $id => $l) {
	print '<option value="'.$id.'"'.($id == $wh ? ' selected' : '').'>'.dol_escape_htmltag($l).'</option>';
}
print '</select>';
print 'Fecha del turno <input type="date" name="fecha" value="'.dol_escape_htmltag($fecha).'" onchange="this.form.submit()">';
print '</form>';

print '<div class="opacitymedium marginbottom">Registra cada regalía o producto dañado del turno. Un servido baja su barquilla/vaso, mix de los sabores y '
	.'demás insumos de la receta; una bandeja o golosina baja ella misma. El sirope y el topping se ven en el conteo de cierre. '
	.'Lo autoriza quien tenga PIN (<a href="'.dol_buildpath('/sabrooskipos/carga/pin.php', 1).'">configurar mi PIN</a>).</div>';

$keep = array('tipo' => GETPOST('tipo', 'aZ09'), 'fk_product' => GETPOSTINT('fk_product'), 'qty' => GETPOST('qty', 'alphanohtml'), 'motivo' => GETPOST('motivo', 'alphanohtml'),
	'sabores' => array_map('intval', (array) GETPOST('sabores', 'array')));
if ($action != 'registrar') {
	$keep = array('tipo' => 'regalia', 'fk_product' => 0, 'qty' => '1', 'motivo' => '', 'sabores' => array());
}

print '<form method="POST" action="'.$self.'" autocomplete="off">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="registrar">';
print '<table class="noborder sbr-form">';
print '<tr class="liste_titre"><td colspan="2">Nueva regalía o dañada</td></tr>';
print '<tr class="oddeven"><td>Tipo</td><td>';
foreach ($tipos as $k => $l) {
	print '<label style="margin-right:16px"><input type="radio" name="tipo" value="'.$k.'"'.($keep['tipo'] == $k ? ' checked' : '').'> '.$l.'</label>';
}
print '</td></tr>';
print '<tr class="oddeven"><td>Producto</td><td><select name="fk_product" id="sbr-prod" class="flat minwidth300"><option value="0"></option>';
foreach ($productos as $cat => $items) {
	print '<optgroup label="'.dol_escape_htmltag($cat).'">';
	foreach ($items as $it) {
		print '<option value="'.$it['id'].'" data-mix="'.$it['mix'].'" data-max="'.$it['max_sabores'].'"'.($keep['fk_product'] == $it['id'] ? ' selected' : '').'>'
			.dol_escape_htmltag($it['label'].' ('.$it['ref'].')').'</option>';
	}
	print '</optgroup>';
}
print '</select>'.ajax_combobox('sbr-prod').'</td></tr>';
print '<tr class="oddeven" id="sbr-sab-row"><td>Sabores <span class="opacitymedium" id="sbr-sab-max"></span></td><td class="sbr-sabores">';
foreach ($saboresMix as $sid => $sl) {
	print '<label><input type="checkbox" name="sabores[]" value="'.$sid.'"'.(in_array($sid, $keep['sabores']) ? ' checked' : '').'> '.dol_escape_htmltag($sl).'</label>';
}
print '</td></tr>';
print '<tr class="oddeven"><td>Cantidad</td><td><input type="text" inputmode="decimal" name="qty" class="flat right width50" value="'.dol_escape_htmltag($keep['qty']).'"></td></tr>';
print '<tr class="oddeven"><td>Motivo / a quién</td><td><input type="text" name="motivo" class="flat minwidth400" maxlength="200" value="'.dol_escape_htmltag($keep['motivo']).'" placeholder="Ej.: Marketing (Naibelis) / se cayó al servir"></td></tr>';
print '<tr class="oddeven"><td>PIN de quien autoriza</td><td><input type="password" name="pin" inputmode="numeric" class="flat width75" maxlength="8" autocomplete="new-password"></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button" value="Registrar"></div>';
print '</form>';

print '<script>
(function(){
	var sel = document.getElementById("sbr-prod"), row = document.getElementById("sbr-sab-row"), mx = document.getElementById("sbr-sab-max");
	function upd(){
		var o = sel.options[sel.selectedIndex], mix = o && o.getAttribute("data-mix") == "1", max = o ? parseInt(o.getAttribute("data-max") || "0", 10) : 0;
		row.style.display = mix ? "" : "none";
		mx.textContent = (mix && max > 0) ? "(máx. " + max + ")" : "";
		if (!mix) { row.querySelectorAll("input").forEach(function(c){ c.checked = false; }); }
	}
	sel.addEventListener("change", upd);
	if (window.jQuery) { jQuery(sel).on("change", upd); }
	upd();
})();
</script>';

// Bajas del día
print '<br><div class="div-table-responsive"><table class="noborder centpercent sbr-table">';
print '<tr class="liste_titre"><td>Registradas en el turno</td><td>Tipo</td><td class="right">Cant.</td><td>Motivo</td><td class="right">Costo $</td><td>Autorizó</td><td>Registró</td><td></td></tr>';
if (!$bajas) {
	print '<tr class="oddeven"><td colspan="8" class="opacitymedium">Ninguna.</td></tr>';
}
$tcosto = array();
foreach ($bajas as $b) {
	$activa = ($b['status'] == 1);
	print '<tr class="oddeven'.($activa ? '' : ' sbr-anulada').'">';
	print '<td>'.dol_escape_htmltag($b['label']).($b['sabores_txt'] ? ' <span class="opacitymedium">('.dol_escape_htmltag($b['sabores_txt']).')</span>' : '').'</td>';
	print '<td>'.dol_escape_htmltag($tipos[$b['tipo']] ?? $b['tipo']).'</td>';
	print '<td class="right">'.sabrooskiposCant($b['qty']).'</td>';
	print '<td>'.dol_escape_htmltag($b['motivo']).'</td>';
	print '<td class="right">'.price($b['costo'], 0, '', 1, -1, 2).'</td>';
	print '<td>'.dol_escape_htmltag($b['autoriza']).'</td>';
	print '<td>'.dol_escape_htmltag($b['registra']).' <span class="opacitymedium">'.dol_print_date($b['datec'], 'hour').'</span></td>';
	print '<td class="right nowraponall">';
	if ($activa) {
		$tcosto[$b['tipo']] = (isset($tcosto[$b['tipo']]) ? $tcosto[$b['tipo']] : 0) + $b['costo'];
		print '<form method="POST" action="'.$self.'" style="display:inline" autocomplete="off" onsubmit="return confirm(\'¿Anular y devolver al móvil?\')">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="anular"><input type="hidden" name="id" value="'.$b['id'].'">';
		print '<input type="password" name="pin" inputmode="numeric" class="flat width50" maxlength="8" placeholder="PIN" autocomplete="new-password"> ';
		print '<input type="submit" class="button smallpaddingimp" value="Anular"></form>';
	} else {
		print '<span class="opacitymedium">Anulada ('.dol_escape_htmltag($b['anula']).', autorizó '.dol_escape_htmltag($b['autoriza_anula']).')</span>';
	}
	print '</td></tr>';
}
foreach ($tcosto as $t => $c) {
	print '<tr class="liste_total"><td colspan="4">Costo total '.dol_escape_htmltag(strtolower($tipos[$t] ?? $t)).'s</td><td class="right">'.price($c, 0, '', 1, -1, 2).'</td><td colspan="3"></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
