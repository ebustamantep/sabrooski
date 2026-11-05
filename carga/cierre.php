<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/carga/cierre.php
 * \ingroup sabrooskipos
 * \brief   Conteo de cierre del móvil: deja cada insumo/congelado en lo contado.
 *          Siropes y toppings → "Consumo del turno (conteo)"; el resto → "Ajuste cierre".
 *          Lógica en lib/sabrooskipos_recetas.lib.php (sabrooskiposRegistrarCierre).
 */

require __DIR__.'/main_carga.inc.php';

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
$data = sabrooskiposRecetasLeer();
$grupos = $data ? sabrooskiposCargaGrupos($db, $data) : array();

$keep = array();
if (GETPOST('action', 'aZ09') == 'guardar') {
	$cont = (array) GETPOST('conteo', 'array');
	$porId = array();
	foreach ($grupos as $g) {
		foreach ($g['items'] as $it) {
			$porId[$it['id']] = $it;
		}
	}
	$errors = array();
	$conteos = array();
	foreach ($cont as $pid => $v) {
		if (!isset($porId[(int) $pid])) {
			continue;
		}
		$it = $porId[(int) $pid];
		$q = sabrooskiposCargaValidar($it, $v, false, $errors);
		if ($q !== null) {
			$conteos[(int) $pid] = $q * $it['factor'];
		}
	}
	$tr = null;
	$r = -1;
	$enTx = !$errors;
	if ($enTx) {
		$db->begin();
		$r = sabrooskiposRegistrarCierre($db, $user, $wh, $conteos, $fecha, $errors);
	}
	// Traspaso de turno: lo que quedó (ya contado) pasa al camión del turno siguiente.
	if ($enTx && $r >= 0 && GETPOST('traspasar', 'int') == 1) {
		$tr = sabrooskiposTraspasoTurno($db, $user, $wh, $fecha, $errors);
		if (!is_array($tr)) {
			$r = -1;
		}
	}
	if ($enTx && $r >= 0) {
		$db->commit();
		setEventMessages('Cierre registrado: '.count($conteos).' productos contados, '.$r.' ajustes.'
			.($tr ? ' Traspasados '.$tr['n'].' productos a '.$tr['destino']['ref'].' (turno del '.dol_print_date(dol_stringtotime($tr['destino']['fecha'].' 12:00:00'), '%d/%m/%Y').').' : ''), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?wh='.$wh.'&fecha='.urlencode($fecha));
		exit;
	}
	if ($enTx) {
		$db->rollback();
	}
	setEventMessages('No se registró el cierre.', $errors, 'errors');
	$keep = $cont; // se vuelve a mostrar lo contado para corregir sin reescribir todo
}

$stMovil = sabrooskiposStockAlmacen($db, $wh);
$siguiente = sabrooskiposTurnoSiguiente($db, $wh, $fecha);
$o = $db->fetch_object($db->query("SELECT MAX(datem) AS d FROM ".MAIN_DB_PREFIX."stock_mouvement WHERE fk_entrepot = ".((int) $wh)
	." AND label LIKE 'Traspaso de turno%' AND datem BETWEEN '".$db->escape($fecha)." 00:00:00' AND '".$db->escape($fecha)." 23:59:59'"));
$yaTraspaso = ($o && $o->d);

llxHeader(sabrooskiposCargaCss(), 'Cierre del móvil', '', '', 0, 0, '', '', '', 'mod-sabrooskipos page-cierre');
print load_fiche_titre('Cierre del móvil (conteo)', '', 'stock');

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" class="sbr-filter">';
print 'Móvil <select name="wh" class="flat" onchange="this.form.submit()">';
foreach ($moviles as $id => $l) {
	print '<option value="'.$id.'"'.($id == $wh ? ' selected' : '').'>'.dol_escape_htmltag($l).'</option>';
}
print '</select>';
print 'Fecha del turno <input type="date" name="fecha" value="'.dol_escape_htmltag($fecha).'" onchange="this.form.submit()">';
print '</form>';

print '<div class="opacitymedium marginbottom">Escribe lo que queda en el camión al cerrar (el mix en <b>gramos</b>, sin punto ni coma: 9,4 kg = 9400).Lo que dejes vacío no se toca. '
	.'Topping y sirope: pesa todo junto y escribe los gramos. Pirulín y Oreo: la diferencia es el consumo del turno. '
	.'El resto (también topping y sirope) ya lo descontaron las ventas: la diferencia queda como ajuste '
	.'y se ve en ReportesVentas → Insumos del móvil.</div>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="guardar">';
print '<input type="hidden" name="wh" value="'.$wh.'">';
print '<input type="hidden" name="fecha" value="'.dol_escape_htmltag($fecha).'">';
print '<div class="div-table-responsive"><table class="noborder centpercent sbr-table">';
foreach ($grupos as $g) {
	print '<tr class="liste_titre"><td>'.dol_escape_htmltag($g['titulo']).'</td><td>Unidad</td><td class="right">Según sistema</td><td class="right">Conteo</td></tr>';
	foreach ($g['items'] as $it) {
		$f = $it['factor'] > 0 ? $it['factor'] : 1;
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($it['label']).' <span class="opacitymedium">'.dol_escape_htmltag($it['ref']).'</span></td>';
		print '<td>'.dol_escape_htmltag($it['unidad']).'</td>';
		print '<td class="right">'.sabrooskiposCant((isset($stMovil[$it['id']]) ? $stMovil[$it['id']] : 0) / $f).'</td>';
		$val = (isset($keep[$it['id']]) ? (string) $keep[$it['id']] : '');
		print '<td class="right"><input type="text" inputmode="'.(!empty($it['entero']) ? 'numeric' : 'decimal').'" class="flat right width75" name="conteo['.$it['id'].']" value="'.dol_escape_htmltag($val).'"'
			.(!empty($it['entero']) ? ' placeholder="gramos"' : '').'></td></tr>';
	}
}
print '</table></div>';
print '<div class="center"><input type="submit" class="button" value="Registrar cierre">';
if ($siguiente) {
	$fsig = dol_print_date(dol_stringtotime($siguiente['fecha'].' 12:00:00'), '%d/%m/%Y');
	print ' <button type="submit" class="button button-save" name="traspasar" value="1" onclick="return confirm(\''
		.dol_escape_js('¿Registrar el cierre y pasar todo lo que queda en el camión a '.$siguiente['ref'].' (turno del '.$fsig.')?').'\');">'
		.'Registrar cierre y traspasar a '.dol_escape_htmltag($siguiente['ref']).'</button>';
}
print '</div>';
if ($siguiente) {
	print '<div class="center opacitymedium">El traspaso pasa todo lo que queda (lo contado y, lo que no se contó, según el sistema) a '
		.dol_escape_htmltag($siguiente['ref']).' como existencia inicial del turno del '.$fsig.'. Después, en Carga del camión → Reposición, se repone lo que salió.</div>';
}
if ($yaTraspaso) {
	print '<div class="warning">Este móvil ya traspasó su inventario en este turno.</div>';
}
print '</form>';

llxFooter();
$db->close();
