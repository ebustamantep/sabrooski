<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/carga/index.php
 * \ingroup sabrooskipos
 * \brief   Planilla de carga del camión: insumos y congelados en el orden de la planilla.
 *          Existencia inicial / Reposición = traslado Principal → móvil;
 *          Entrada a Principal = compras o conteo inicial de Principal.
 *          Lógica en lib/sabrooskipos_recetas.lib.php (sabrooskiposRegistrarCarga).
 */

require __DIR__.'/main_carga.inc.php';

if (!$user->admin && !$user->hasRight('sabrooskipos', 'carga', 'write')) {
	accessforbidden();
}

$tipos = array('inicial' => 'Existencia inicial (Principal → móvil)', 'reposicion' => 'Reposición / carga (Principal → móvil)',
	'principal' => 'Entrada a Principal (compras o conteo)');
$tipo = GETPOST('tipo', 'aZ09');
if (!isset($tipos[$tipo])) {
	$tipo = 'reposicion';
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
$main = sabrooskiposAlmacenPrincipal($db);
$data = sabrooskiposRecetasLeer();
$grupos = $data ? sabrooskiposCargaGrupos($db, $data) : array();

if (GETPOST('action', 'aZ09') == 'guardar') {
	$cant = GETPOST('qty', 'array');
	$factor = array();
	foreach ($grupos as $g) {
		foreach ($g['items'] as $it) {
			$factor[$it['id']] = $it['factor'];
		}
	}
	$cantidades = array();
	foreach ((array) $cant as $pid => $v) {
		$v = price2num($v);
		if (isset($factor[(int) $pid]) && $v !== '' && (float) $v > 0) {
			$cantidades[(int) $pid] = (float) $v * $factor[(int) $pid];
		}
	}
	$errors = array();
	$db->begin();
	$r = sabrooskiposRegistrarCarga($db, $user, $tipo, $wh, $cantidades, $fecha, $errors);
	if ($r > 0) {
		$db->commit();
		setEventMessages(($tipo == 'principal' ? count($cantidades).' entradas registradas en Principal.' : 'Carga registrada: '.count($cantidades).' productos trasladados a '.$moviles[$wh].'.'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?tipo='.urlencode($tipo).'&wh='.$wh.'&fecha='.urlencode($fecha));
		exit;
	}
	$db->rollback();
	setEventMessages('No se registró la carga.', $errors, 'errors');
}

$stMain = sabrooskiposStockAlmacen($db, $main);
$stMovil = sabrooskiposStockAlmacen($db, $wh);

llxHeader(sabrooskiposCargaCss(), 'Carga del camión', '', '', 0, 0, '', '', '', 'mod-sabrooskipos page-carga');
print load_fiche_titre('Carga del camión', '', 'stock');

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" class="sbr-filter">';
print 'Tipo <select name="tipo" class="flat" onchange="this.form.submit()">';
foreach ($tipos as $k => $l) {
	print '<option value="'.$k.'"'.($k == $tipo ? ' selected' : '').'>'.dol_escape_htmltag($l).'</option>';
}
print '</select>';
if ($tipo != 'principal') {
	print 'Móvil <select name="wh" class="flat" onchange="this.form.submit()">';
	foreach ($moviles as $id => $l) {
		print '<option value="'.$id.'"'.($id == $wh ? ' selected' : '').'>'.dol_escape_htmltag($l).'</option>';
	}
	print '</select>';
}
print 'Fecha <input type="date" name="fecha" value="'.dol_escape_htmltag($fecha).'" onchange="this.form.submit()">';
print '</form>';

if (!$data) {
	print '<div class="error">No se pudo leer recetas_sabrooski.json.</div>';
} elseif ($main <= 0) {
	print '<div class="error">Configura el almacén principal en Setup → Sabrooski POS → Configuración.</div>';
} else {
	print '<div class="opacitymedium marginbottom">'.($tipo == 'principal'
		? 'Suma la cantidad al almacén Principal (compras o conteo inicial). Después se carga el móvil con "Existencia inicial" o "Reposición".'
		: 'Traslada de Principal al móvil. No se puede cargar más de lo que hay en Principal. El mix se carga en kg.').'</div>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="guardar">';
	print '<input type="hidden" name="tipo" value="'.dol_escape_htmltag($tipo).'">';
	print '<input type="hidden" name="wh" value="'.$wh.'">';
	print '<input type="hidden" name="fecha" value="'.dol_escape_htmltag($fecha).'">';
	print '<div class="div-table-responsive"><table class="noborder centpercent sbr-table">';
	foreach ($grupos as $g) {
		print '<tr class="liste_titre"><td>'.dol_escape_htmltag($g['titulo']).'</td><td>Unidad</td><td class="right">En Principal</td>'
			.($tipo != 'principal' ? '<td class="right">En el móvil</td>' : '').'<td class="right">Cantidad</td></tr>';
		foreach ($g['items'] as $it) {
			$f = $it['factor'] > 0 ? $it['factor'] : 1;
			print '<tr class="oddeven"><td>'.dol_escape_htmltag($it['label']).' <span class="opacitymedium">'.dol_escape_htmltag($it['ref']).'</span></td>';
			print '<td>'.dol_escape_htmltag($it['unidad']).'</td>';
			print '<td class="right">'.sabrooskiposCant((isset($stMain[$it['id']]) ? $stMain[$it['id']] : 0) / $f).'</td>';
			if ($tipo != 'principal') {
				print '<td class="right">'.sabrooskiposCant((isset($stMovil[$it['id']]) ? $stMovil[$it['id']] : 0) / $f).'</td>';
			}
			print '<td class="right"><input type="text" inputmode="decimal" class="flat right width75" name="qty['.$it['id'].']" value=""></td></tr>';
		}
	}
	print '</table></div>';
	print '<div class="center"><input type="submit" class="button" value="Registrar '.($tipo == 'principal' ? 'entrada' : 'carga').'"></div>';
	print '</form>';

	// Últimas cargas del almacén (agrupadas por código de inventario)
	$whh = ($tipo == 'principal' ? $main : $wh);
	$sql = "SELECT m.inventorycode, MIN(m.datem) AS fecha, MIN(m.label) AS label, COUNT(DISTINCT m.fk_product) AS n, MIN(u.login) AS login"
		." FROM ".MAIN_DB_PREFIX."stock_mouvement m LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = m.fk_user_author"
		." WHERE m.fk_entrepot = ".((int) $whh)." AND m.value > 0"
		." AND (m.inventorycode LIKE 'CARGA-%' OR m.inventorycode LIKE 'ENTRADA-%')"
		." GROUP BY m.inventorycode ORDER BY MAX(m.rowid) DESC LIMIT 10";
	$resql = $db->query($sql);
	if ($resql && $db->num_rows($resql)) {
		print '<br><div class="div-table-responsive"><table class="noborder centpercent sbr-table">';
		print '<tr class="liste_titre"><td>Últimas cargas</td><td>Fecha</td><td class="right">Productos</td><td>Usuario</td></tr>';
		while ($o = $db->fetch_object($resql)) {
			$l = preg_replace('/ · .*$/u', '', (string) $o->label);
			$url = DOL_URL_ROOT.'/product/stock/movement_list.php?search_inventorycode='.urlencode($o->inventorycode);
			print '<tr class="oddeven"><td><a href="'.$url.'">'.dol_escape_htmltag($l).'</a> <span class="opacitymedium">'.dol_escape_htmltag($o->inventorycode).'</span></td>';
			print '<td>'.dol_print_date($db->jdate($o->fecha), 'dayhour').'</td><td class="right">'.((int) $o->n).'</td><td>'.dol_escape_htmltag((string) $o->login).'</td></tr>';
		}
		print '</table></div>';
	}
}

llxFooter();
$db->close();
