<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/carga/main_carga.inc.php
 * \ingroup sabrooskipos
 * \brief   Entorno común de las páginas de carga y cierre del camión.
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
if (!$res && file_exists(__DIR__."/../../../main.inc.php")) {
	$res = @include __DIR__."/../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos_recetas.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos_turnos.lib.php';

$langs->loadLangs(array("stocks", "sabrooskipos@sabrooskipos"));

if (!isModEnabled('sabrooskipos') || !isModEnabled('stock')) {
	accessforbidden();
}

/**
 * Almacenes de los móviles (los de cada terminal del TakePOS), con su nombre.
 *
 * @return array<int,string>  [id almacén => "Movil01PM (terminal 2)"]
 */
function sabrooskiposMoviles()
{
	global $db;
	$res = array();
	for ($t = 1; $t <= max(1, getDolGlobalInt('TAKEPOS_NUM_TERMINALS', 1)); $t++) {
		$wh = getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$t);
		if ($wh > 0 && !isset($res[$wh])) {
			$resql = $db->query("SELECT ref FROM ".MAIN_DB_PREFIX."entrepot WHERE rowid = ".$wh);
			$o = $resql ? $db->fetch_object($resql) : null;
			$res[$wh] = ($o ? $o->ref : 'Almacén '.$wh).' (terminal '.$t.')';
		}
	}
	return $res;
}

/**
 * Cantidad con decimales solo si los tiene.
 *
 * @param float $v
 * @return string
 */
function sabrooskiposCant($v)
{
	return price(price2num($v, 'MS'), 0, '', 0, 0, 3);
}

/**
 * CSS común.
 *
 * @return string
 */
function sabrooskiposCargaCss()
{
	return '<style>.sbr-filter{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;margin-bottom:10px}'
		.'.sbr-table td,.sbr-table th{white-space:nowrap}.sbr-table td:first-child{white-space:normal}'
		.'.sbr-pasos{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0 14px}.sbr-paso{padding:4px 10px;border-radius:12px;background:#eee;color:#555}'
		.'.sbr-paso.hecho{background:#dff0d8;color:#2d5a2d}.sbr-paso.actual{background:#2c6ebd;color:#fff;font-weight:bold}</style>';
}

/**
 * Turno con el que trabaja una página de carga/cierre/bajas (v1.16): el elegido en el
 * parámetro "sesion" (si es de este móvil) o el actual del terminal (la caja abierta o, si
 * no hay, la última cerrada). null sin POSPlus o sin sesiones: se trabaja por fecha.
 *
 * @param DoliDB $db
 * @param int    $wh  almacén del móvil
 * @return array|null
 */
function sabrooskiposTurnoElegido($db, $wh)
{
	require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos_turnos.lib.php';
	$term = sabrooskiposTerminalDeAlmacen($wh);
	if ($term === '' || !sabrooskiposPosplusActivo($db)) {
		return null;
	}
	$sid = GETPOSTINT('sesion');
	$t = ($sid > 0 ? sabrooskiposTurnoLeer($db, $sid) : null);
	if ($t && $t['terminal'] !== $term) {
		$t = null;
	}
	if ($t) {
		$m = sabrooskiposTurnoMarca($db, $t['id'], $wh);
		$t['conteo'] = $m['conteo'];
		$t['devol'] = $m['devol'];
	} else {
		$t = sabrooskiposTurnoActual($db, $term);
	}
	if ($t && !sabrooskiposModoTurnos($db, $t['fecha'])) {
		return null;
	}
	return $t;
}

/**
 * Selector de turno (los de los últimos 7 días del móvil), para corregir uno pasado.
 *
 * @param DoliDB     $db
 * @param int        $wh
 * @param array|null $turno  el elegido
 * @param string     $onchange
 * @return string  HTML ('' sin turnos)
 */
function sabrooskiposSelectTurno($db, $wh, $turno, $onchange = 'this.form.submit()')
{
	$term = sabrooskiposTerminalDeAlmacen($wh);
	$lista = ($term !== '' ? sabrooskiposTurnosRecientes($db, $term, 7) : array());
	if (!$lista) {
		return '';
	}
	$html = 'Turno <select name="sesion" class="flat minwidth200" onchange="'.dol_escape_htmltag($onchange).'">';
	foreach ($lista as $t) {
		$html .= '<option value="'.$t['id'].'"'.($turno && $turno['id'] == $t['id'] ? ' selected' : '').'>'
			.dol_escape_htmltag(dol_print_date(dol_stringtotime($t['fecha'].' 12:00:00'), '%d/%m').' · '.$t['label']).'</option>';
	}
	return $html.'</select>';
}

/**
 * Tabla de conteo del camión (Cierre y Cambio de turno).
 *
 * @param array $grupos  sabrooskiposCargaGrupos()
 * @param array $stock   [fk_product => existencia del móvil]
 * @param array $keep    lo escrito (para volver a mostrarlo si hubo un error)
 * @return string
 */
function sabrooskiposFormConteo($grupos, $stock, $keep = array())
{
	$html = '<div class="div-table-responsive"><table class="noborder centpercent sbr-table">';
	foreach ($grupos as $g) {
		$html .= '<tr class="liste_titre"><td>'.dol_escape_htmltag($g['titulo']).'</td><td>Unidad</td><td class="right">Según sistema</td><td class="right">Conteo</td></tr>';
		foreach ($g['items'] as $it) {
			$f = $it['factor'] > 0 ? $it['factor'] : 1;
			$val = (isset($keep[$it['id']]) ? (string) $keep[$it['id']] : '');
			$html .= '<tr class="oddeven"><td>'.dol_escape_htmltag($it['label']).' <span class="opacitymedium">'.dol_escape_htmltag($it['ref']).'</span></td>';
			$html .= '<td>'.dol_escape_htmltag($it['unidad']).'</td>';
			$html .= '<td class="right">'.sabrooskiposCant((isset($stock[$it['id']]) ? $stock[$it['id']] : 0) / $f).'</td>';
			$html .= '<td class="right"><input type="text" inputmode="'.(!empty($it['entero']) ? 'numeric' : 'decimal').'" class="flat right width75" name="conteo['.$it['id'].']" value="'.dol_escape_htmltag($val).'"'
				.(!empty($it['entero']) ? ' placeholder="gramos"' : '').'></td></tr>';
		}
	}
	return $html.'</table></div>';
}

/**
 * Lee y valida los conteos enviados (conteo[pid]) según las reglas de cada item.
 *
 * @param array $grupos
 * @param array $cont    GETPOST('conteo', 'array')
 * @param array $errors  (por referencia)
 * @return array<int,float>  [fk_product => cantidad en unidad de control]
 */
function sabrooskiposLeerConteos($grupos, $cont, &$errors)
{
	$porId = array();
	foreach ($grupos as $g) {
		foreach ($g['items'] as $it) {
			$porId[$it['id']] = $it;
		}
	}
	$conteos = array();
	foreach ((array) $cont as $pid => $v) {
		if (!isset($porId[(int) $pid])) {
			continue;
		}
		$it = $porId[(int) $pid];
		$q = sabrooskiposCargaValidar($it, $v, false, $errors);
		if ($q !== null) {
			$conteos[(int) $pid] = $q * $it['factor'];
		}
	}
	return $conteos;
}
