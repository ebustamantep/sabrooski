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
 *          v1.16: el conteo es de un turno (sesión de caja POSPlus). El flujo normal está en
 *          Cambio de turno (turno.php); esta página queda para conteos sueltos y correcciones.
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
// Por turnos (POSPlus) se trabaja con el turno; si no, con la fecha como antes.
$turno = sabrooskiposTurnoElegido($db, $wh);
$fecha = GETPOST('fecha', 'alphanohtml');
if ($turno) {
	$fecha = $turno['fecha'];
} elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
	$fecha = dol_print_date(dol_now(), '%Y-%m-%d');
}
$data = sabrooskiposRecetasLeer();
$grupos = $data ? sabrooskiposCargaGrupos($db, $data) : array();
// El conteo se compara con la existencia de AHORA: solo tiene sentido en el turno actual (o
// el último cerrado). En uno anterior lo movido después cambiaría las diferencias.
$actual = ($turno ? sabrooskiposTurnoActual($db, $turno['terminal']) : null);
$pasado = ($turno && $actual && $actual['id'] != $turno['id']);

$keep = array();
if (GETPOST('action', 'aZ09') == 'guardar') {
	$cont = (array) GETPOST('conteo', 'array');
	$errors = array();
	if ($pasado && !$user->admin) {
		$errors[] = 'Solo un administrador puede contar un turno anterior al actual.';
	}
	$conteos = sabrooskiposLeerConteos($grupos, $cont, $errors);
	$r = -1;
	$enTx = !$errors;
	if ($enTx) {
		$db->begin();
		$r = sabrooskiposRegistrarCierre($db, $user, $wh, $conteos, $fecha, $errors, $turno);
	}
	if ($enTx && $r >= 0) {
		$db->commit();
		setEventMessages('Cierre registrado: '.count($conteos).' productos contados, '.$r.' ajustes'.($turno ? ' ('.$turno['corto'].')' : '').'.', null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?wh='.$wh.($turno ? '&sesion='.$turno['id'] : '&fecha='.urlencode($fecha)));
		exit;
	}
	if ($enTx) {
		$db->rollback();
	}
	setEventMessages('No se registró el cierre.', $errors, 'errors');
	$keep = $cont; // se vuelve a mostrar lo contado para corregir sin reescribir todo
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
if ($turno) {
	print sabrooskiposSelectTurno($db, $wh, $turno);
} else {
	print 'Fecha del turno <input type="date" name="fecha" value="'.dol_escape_htmltag($fecha).'" onchange="this.form.submit()">';
}
print '</form>';

if ($turno) {
	print '<div class="info">El flujo normal del cambio de turno y del cierre del día está en <a href="'.dol_buildpath('/sabrooskipos/carga/turno.php', 1).'?wh='.$wh.'"><b>Cambio de turno</b></a>. '
		.'Aquí se cuenta o se corrige el conteo de un turno: '.($turno['abierta']
			? '<b>la caja sigue abierta</b>, el conteo queda a la hora actual.'
			: 'los ajustes se fechan en el cierre de caja del turno ('.dol_escape_htmltag(substr($turno['date_close'], 11, 5)).').')
		.($turno['conteo'] !== '' ? ' Este turno ya se contó ('.dol_escape_htmltag(substr($turno['conteo'], 11, 5)).'): contar de nuevo ajusta a la nueva cifra.' : '')
		.'</div>';
	if ($pasado) {
		print '<div class="warning">Este no es el turno actual ('.dol_escape_htmltag($actual['corto'].' del '.dol_print_date(dol_stringtotime($actual['fecha'].' 12:00:00'), '%d/%m')).'). '
			.'El conteo se compara con la existencia de ahora, así que las diferencias de este turno saldrían mal. Solo un administrador puede hacerlo.</div>';
	}
}

print '<div class="opacitymedium marginbottom">Escribe lo que queda en el camión (el mix en <b>gramos</b>, sin punto ni coma: 9,4 kg = 9400). Lo que dejes vacío no se toca. '
	.'Topping y sirope: pesa todo junto y escribe los gramos. Pirulín y Oreo: la diferencia es el consumo del turno. '
	.'El resto (también topping y sirope) ya lo descontaron las ventas: la diferencia queda como ajuste '
	.'y se ve en ReportesVentas → Insumos del móvil.</div>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="guardar">';
print '<input type="hidden" name="wh" value="'.$wh.'">';
print '<input type="hidden" name="fecha" value="'.dol_escape_htmltag($fecha).'">';
if ($turno) {
	print '<input type="hidden" name="sesion" value="'.$turno['id'].'">';
}
print sabrooskiposFormConteo($grupos, $stMovil, $keep);
print '<div class="center"><input type="submit" class="button" value="Registrar cierre'.($turno ? ' del '.dol_escape_htmltag($turno['corto']) : '').'"></div>';
print '</form>';

llxFooter();
$db->close();
