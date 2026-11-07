<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/lib/sabrooskipos_turnos.lib.php
 * \ingroup sabrooskipos
 * \brief   Turnos (v1.16): un camión = un terminal = un almacén, con varios turnos al día.
 *          Un turno es una sesión de caja de POSPlus (llx_posplus_cashsession): la 1.ª del
 *          día es el AM y la 2.ª el PM.
 *
 * Ventana del turno: (t0, t1], igual que POSPlus. t0 = date_start de la sesión (el cierre
 * de la sesión anterior del terminal; si no hubo, el inicio del día), t1 = date_close (o
 * ahora, si sigue abierta). Las fechas son las del servidor, tal como se guardan.
 *
 * Regla del hueco (entre el cierre X de un turno y la apertura del siguiente): el conteo,
 * la devolución a Principal y las bajas registradas en el hueco son del turno que cerró
 * (sus movimientos se fechan en su date_close); la existencia inicial y la reposición son
 * del turno siguiente (quedan a la hora real, que ya cae en su ventana).
 *
 * Modo "legacy" (día completo con las reglas de antes): sin POSPlus, un día sin sesiones o
 * una fecha anterior a SABROOSKIPOS_TURNOS_DESDE.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

/**
 * ¿Está POSPlus activo y con su tabla de sesiones?
 *
 * @param DoliDB $db
 * @return bool
 */
function sabrooskiposPosplusActivo($db)
{
	static $ok = null;
	if ($ok === null) {
		$ok = isModEnabled('posplus') && (bool) $db->query("SELECT 1 FROM ".MAIN_DB_PREFIX."posplus_cashsession LIMIT 1");
	}
	return $ok;
}

/**
 * ¿La fecha se trabaja por turnos (POSPlus) o con las reglas de día completo?
 *
 * @param DoliDB $db
 * @param string $fecha  'YYYY-MM-DD'
 * @return bool
 */
function sabrooskiposModoTurnos($db, $fecha)
{
	$desde = getDolGlobalString('SABROOSKIPOS_TURNOS_DESDE');
	return sabrooskiposPosplusActivo($db) && ($desde === '' || $fecha >= $desde);
}

/**
 * Terminal TakePOS de un almacén (inverso de CASHDESK_ID_WAREHOUSE<t>).
 *
 * @param int $wh
 * @return string  '' si ningún terminal usa ese almacén
 */
function sabrooskiposTerminalDeAlmacen($wh)
{
	for ($t = 1; $t <= max(1, getDolGlobalInt('TAKEPOS_NUM_TERMINALS', 1)); $t++) {
		if ($wh > 0 && getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$t) == (int) $wh) {
			return (string) $t;
		}
	}
	return '';
}

/**
 * Turnos (sesiones de caja) de un terminal abiertos en una fecha, en orden.
 *
 * @param DoliDB $db
 * @param string $terminal
 * @param string $fecha  'YYYY-MM-DD'
 * @return array<int,array>  lista (0..n-1) de turnos; ver sabrooskiposTurnoFila()
 */
function sabrooskiposTurnos($db, $terminal, $fecha)
{
	global $conf;
	$out = array();
	if (!sabrooskiposPosplusActivo($db) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
		return $out;
	}
	$pre = MAIN_DB_PREFIX;
	$sql = "SELECT c.rowid, c.ref, c.terminal, c.status, c.date_open, c.date_start, c.date_close, c.fk_user_open, c.fk_user_close,"
		." (SELECT COUNT(*) FROM ".$pre."posplus_cashsession p WHERE p.entity = c.entity AND p.terminal = c.terminal AND p.rowid < c.rowid AND p.status <> 0) AS previas"
		." FROM ".$pre."posplus_cashsession c"
		." WHERE c.entity = ".((int) $conf->entity)." AND c.terminal = '".$db->escape((string) $terminal)."'"
		." AND c.date_open >= '".$db->escape($fecha)." 00:00:00' AND c.date_open <= '".$db->escape($fecha)." 23:59:59'"
		." ORDER BY c.date_open, c.rowid";
	$resql = $db->query($sql);
	$n = 0;
	while ($resql && ($o = $db->fetch_object($resql))) {
		$out[] = sabrooskiposTurnoFila($db, $o, ++$n);
	}
	return $out;
}

/**
 * Arma el turno a partir de la fila de llx_posplus_cashsession.
 *
 * @param DoliDB $db
 * @param object $o   fila (con la columna calculada "previas")
 * @param int    $n   orden en el día (1 = AM, 2 = PM)
 * @return array{id:int,ref:string,terminal:string,wh:int,n:int,fecha:string,label:string,corto:string,status:int,abierta:bool,t0:string,t1:string,legacy:bool,ids:int[]}
 */
function sabrooskiposTurnoFila($db, $o, $n)
{
	$fecha = substr((string) $o->date_open, 0, 10);
	$abierta = ((int) $o->status == 0);
	$t0 = (string) $o->date_start;
	if ((int) $o->previas == 0 || $t0 === '') {
		// Primera sesión del terminal: el turno toma desde el inicio del día (la carga de la mañana).
		$t0 = date('Y-m-d', strtotime($fecha.' -1 day')).' 23:59:59';
	}
	$t1 = ($abierta ? $db->idate(dol_now()) : (string) $o->date_close);
	$nombre = ($n == 1 ? 'Turno 1 (AM)' : ($n == 2 ? 'Turno 2 (PM)' : 'Turno '.$n));
	$horas = substr((string) $o->date_open, 11, 5).'–'.($abierta ? 'en curso' : substr((string) $o->date_close, 11, 5));
	return array('id' => (int) $o->rowid, 'ref' => (string) $o->ref, 'terminal' => (string) $o->terminal,
		'wh' => getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$o->terminal), 'n' => $n, 'fecha' => $fecha,
		'corto' => $nombre, 'label' => $nombre.' '.$horas, 'status' => (int) $o->status, 'abierta' => $abierta,
		'date_open' => (string) $o->date_open, 'date_close' => (string) $o->date_close,
		't0' => $t0, 't1' => $t1, 'legacy' => false, 'ids' => array((int) $o->rowid));
}

/**
 * Un turno por id de sesión (con su número en el día).
 *
 * @param DoliDB $db
 * @param int    $id
 * @return array|null
 */
function sabrooskiposTurnoLeer($db, $id)
{
	if ($id <= 0 || !sabrooskiposPosplusActivo($db)) {
		return null;
	}
	$o = $db->fetch_object($db->query("SELECT terminal, date_open FROM ".MAIN_DB_PREFIX."posplus_cashsession WHERE rowid = ".((int) $id)));
	if (!$o) {
		return null;
	}
	foreach (sabrooskiposTurnos($db, (string) $o->terminal, substr((string) $o->date_open, 0, 10)) as $t) {
		if ($t['id'] == $id) {
			return $t;
		}
	}
	return null;
}

/**
 * Ventana de un reporte: un turno, el día completo por turnos, o el día legacy.
 *
 * @param DoliDB $db
 * @param string $terminal
 * @param string $fecha    'YYYY-MM-DD'
 * @param int    $turnoId  0 = día completo
 * @return array  como sabrooskiposTurnoFila(); en día completo id = 0 e ids = todas las sesiones
 */
function sabrooskiposTurnoVentana($db, $terminal, $fecha, $turnoId = 0)
{
	$legacy = array('id' => 0, 'ref' => '', 'terminal' => (string) $terminal, 'wh' => getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$terminal),
		'n' => 0, 'fecha' => $fecha, 'corto' => 'Día completo', 'label' => 'Día completo', 'status' => 1, 'abierta' => false,
		'date_open' => '', 'date_close' => '', 't0' => $fecha.' 00:00:00', 't1' => $fecha.' 23:59:59', 'legacy' => true, 'ids' => array());
	if (!sabrooskiposModoTurnos($db, $fecha)) {
		return $legacy;
	}
	$turnos = sabrooskiposTurnos($db, $terminal, $fecha);
	if (!$turnos) {
		return $legacy;
	}
	foreach ($turnos as $t) {
		if ($turnoId > 0 && $t['id'] == $turnoId) {
			return $t;
		}
	}
	$ult = end($turnos);
	$dia = $turnos[0];
	$dia['id'] = 0;
	$dia['n'] = 0;
	$dia['corto'] = 'Día completo';
	$dia['label'] = 'Día completo ('.count($turnos).' turno'.(count($turnos) > 1 ? 's' : '').')';
	$dia['t1'] = $ult['t1'];
	$dia['abierta'] = $ult['abierta'];
	$dia['date_close'] = $ult['date_close'];
	$dia['ids'] = array_map(function ($t) {
		return $t['id'];
	}, $turnos);
	return $dia;
}

/**
 * Condición SQL "dentro de la ventana" sobre una columna datetime.
 *
 * @param DoliDB $db
 * @param array  $v    ventana (sabrooskiposTurnoVentana)
 * @param string $col
 * @return string
 */
function sabrooskiposSqlEnTurno($db, $v, $col)
{
	if ($v['legacy']) {
		return $col." BETWEEN '".$db->escape($v['t0'])."' AND '".$db->escape($v['t1'])."'";
	}
	return "(".$col." > '".$db->escape($v['t0'])."' AND ".$col." <= '".$db->escape($v['t1'])."')";
}

/**
 * Condición SQL "antes de la ventana" (existencia anterior).
 *
 * @param DoliDB $db
 * @param array  $v
 * @param string $col
 * @return string
 */
function sabrooskiposSqlAntesTurno($db, $v, $col)
{
	return $col.($v['legacy'] ? " < '" : " <= '").$db->escape($v['t0'])."'";
}

/**
 * Turno actual de un terminal: la sesión abierta, o si no hay, la última cerrada.
 * Agrega 'conteo' y 'devol' (marcas de sabrooskipos_turno: fecha o '').
 *
 * @param DoliDB $db
 * @param string $terminal
 * @return array|null  null sin POSPlus o sin sesiones
 */
function sabrooskiposTurnoActual($db, $terminal)
{
	global $conf;
	if (!sabrooskiposPosplusActivo($db) || (string) $terminal === '') {
		return null;
	}
	$pre = MAIN_DB_PREFIX;
	$base = "SELECT rowid FROM ".$pre."posplus_cashsession WHERE entity = ".((int) $conf->entity)." AND terminal = '".$db->escape((string) $terminal)."'";
	$o = $db->fetch_object($db->query($base." AND status = 0 ORDER BY rowid DESC LIMIT 1"));
	if (!$o) {
		$o = $db->fetch_object($db->query($base." AND status <> 0 ORDER BY date_close DESC, rowid DESC LIMIT 1"));
	}
	if (!$o) {
		return null;
	}
	$t = sabrooskiposTurnoLeer($db, (int) $o->rowid);
	if ($t) {
		$m = sabrooskiposTurnoMarca($db, $t['id'], $t['wh']);
		$t['conteo'] = $m['conteo'];
		$t['devol'] = $m['devol'];
	}
	return $t;
}

/**
 * Turnos recientes de un terminal (para elegir uno pasado), los más nuevos primero.
 *
 * @param DoliDB $db
 * @param string $terminal
 * @param int    $dias
 * @return array<int,array>
 */
function sabrooskiposTurnosRecientes($db, $terminal, $dias = 7)
{
	global $conf;
	$out = array();
	if (!sabrooskiposPosplusActivo($db)) {
		return $out;
	}
	$desde = dol_print_date(dol_time_plus_duree(dol_now(), -$dias, 'd'), '%Y-%m-%d');
	$resql = $db->query("SELECT DISTINCT DATE(date_open) AS f FROM ".MAIN_DB_PREFIX."posplus_cashsession WHERE entity = ".((int) $conf->entity)
		." AND terminal = '".$db->escape((string) $terminal)."' AND date_open >= '".$db->escape($desde)." 00:00:00' ORDER BY f DESC");
	$fechas = array();
	while ($resql && ($o = $db->fetch_object($resql))) {
		$fechas[] = (string) $o->f;
	}
	foreach ($fechas as $f) {
		foreach (array_reverse(sabrooskiposTurnos($db, $terminal, $f)) as $t) {
			$out[] = $t;
		}
	}
	return $out;
}

/**
 * Marcas de un turno en un almacén: cuándo se contó y cuándo se devolvió a Principal.
 *
 * @param DoliDB $db
 * @param int    $sid
 * @param int    $wh
 * @return array{conteo:string,devol:string,conteo_code:string,devol_code:string}
 */
function sabrooskiposTurnoMarca($db, $sid, $wh)
{
	$m = array('conteo' => '', 'devol' => '', 'conteo_code' => '', 'devol_code' => '');
	$resql = $db->query("SELECT conteo_date, conteo_code, devol_date, devol_code FROM ".MAIN_DB_PREFIX."sabrooskipos_turno"
		." WHERE fk_cashsession = ".((int) $sid)." AND fk_entrepot = ".((int) $wh));
	if ($resql && ($o = $db->fetch_object($resql))) {
		$m = array('conteo' => (string) $o->conteo_date, 'devol' => (string) $o->devol_date,
			'conteo_code' => (string) $o->conteo_code, 'devol_code' => (string) $o->devol_code);
	}
	return $m;
}

/**
 * Marca el conteo o la devolución de un turno (crea o actualiza la fila).
 *
 * @param DoliDB $db
 * @param User   $user
 * @param int    $sid
 * @param int    $wh
 * @param string $que   'conteo' | 'devol'
 * @param string $code  inventorycode de los movimientos
 * @return bool
 */
function sabrooskiposTurnoMarcar($db, $user, $sid, $wh, $que, $code)
{
	global $conf;
	$pre = MAIN_DB_PREFIX;
	$c = ($que == 'devol' ? 'devol' : 'conteo');
	$now = $db->idate(dol_now());
	$o = $db->fetch_object($db->query("SELECT rowid FROM ".$pre."sabrooskipos_turno WHERE fk_cashsession = ".((int) $sid)." AND fk_entrepot = ".((int) $wh)));
	if ($o) {
		return (bool) $db->query("UPDATE ".$pre."sabrooskipos_turno SET ".$c."_date = '".$now."', ".$c."_code = '".$db->escape($code)."', fk_user_".$c." = ".((int) $user->id)
			." WHERE rowid = ".((int) $o->rowid));
	}
	return (bool) $db->query("INSERT INTO ".$pre."sabrooskipos_turno (entity, fk_cashsession, fk_entrepot, ".$c."_date, ".$c."_code, fk_user_".$c.")"
		." VALUES (".((int) $conf->entity).", ".((int) $sid).", ".((int) $wh).", '".$now."', '".$db->escape($code)."', ".((int) $user->id).")");
}

/**
 * Fecha los movimientos de una operación de cierre (conteo, devolución, baja) en el turno:
 * si el turno ya cerró (estamos en el hueco o es un turno pasado), en su date_close; si
 * sigue abierto, quedan a la hora real (ya dentro de su ventana).
 *
 * @param DoliDB     $db
 * @param array|null $turno
 * @param string     $where  condición sobre llx_stock_mouvement
 * @return void
 */
function sabrooskiposSellarCierre($db, $turno, $where)
{
	if ($turno && empty($turno['legacy']) && empty($turno['abierta']) && $turno['t1'] !== '') {
		$db->query("UPDATE ".MAIN_DB_PREFIX."stock_mouvement SET datem = '".$db->escape($turno['t1'])."' WHERE ".$where);
	}
}

/**
 * Lo que salió de un móvil en un turno, por producto (existencia + cargas − cierre): ventas,
 * regalías/dañadas y diferencias del conteo. Sirve para sugerir la reposición.
 *
 * @param DoliDB $db
 * @param array  $turno
 * @return array<int,float>  [fk_product => cantidad]
 */
function sabrooskiposSalioEnTurno($db, $turno)
{
	require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos_reportes.lib.php';
	$data = sabrooskiposReporteDatos($db, $turno['fecha'], $turno['terminal'], $turno['id']);
	$out = array();
	foreach ($data['productos'] as $pid => $p) {
		if (empty($p['sin_stock'])) {
			$v = $p['ex'] + $p['rep'] - $p['cierre'];
			if ($v > 0.0001) {
				$out[(int) $pid] = $v;
			}
		}
	}
	return $out;
}
