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

if (GETPOST('action', 'aZ09') == 'guardar') {
	$cont = GETPOST('conteo', 'array');
	$factor = array();
	foreach ($grupos as $g) {
		foreach ($g['items'] as $it) {
			$factor[$it['id']] = $it['factor'];
		}
	}
	$conteos = array();
	foreach ((array) $cont as $pid => $v) {
		$v = trim((string) $v);
		if (isset($factor[(int) $pid]) && $v !== '') {
			$conteos[(int) $pid] = (float) price2num($v) * $factor[(int) $pid];
		}
	}
	$errors = array();
	$db->begin();
	$r = sabrooskiposRegistrarCierre($db, $user, $wh, $conteos, $fecha, $errors);
	if ($r >= 0) {
		$db->commit();
		setEventMessages('Cierre registrado: '.count($conteos).' productos contados, '.$r.' ajustes.', null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?wh='.$wh.'&fecha='.urlencode($fecha));
		exit;
	}
	$db->rollback();
	setEventMessages('No se registró el cierre.', $errors, 'errors');
}

$stMovil = sabrooskiposStockAlmacen($db, $wh);

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

print '<div class="opacitymedium marginbottom">Escribe lo que queda en el camión al cerrar (el mix en kg). Lo que dejes vacío no se toca. '
	.'Siropes y toppings: la diferencia es el consumo del turno. El resto ya lo descontaron las ventas: la diferencia queda como ajuste '
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
		print '<td class="right"><input type="text" inputmode="decimal" class="flat right width75" name="conteo['.$it['id'].']" value=""></td></tr>';
	}
}
print '</table></div>';
print '<div class="center"><input type="submit" class="button" value="Registrar cierre"></div>';
print '</form>';

llxFooter();
$db->close();
