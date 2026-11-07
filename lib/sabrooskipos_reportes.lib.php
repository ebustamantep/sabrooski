<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/lib/sabrooskipos_reportes.lib.php
 * \ingroup sabrooskipos
 * \brief   ReportesVentas: las planillas del turno (Camión congelados, Pago móvil y
 *          puntos, Especificaciones de ventas, Inventario de ventas) calculadas con los
 *          datos del sistema. Un turno = sesión de caja POSPlus del terminal (v1.16; ver
 *          sabrooskipos_turnos.lib.php); sin POSPlus, o antes de SABROOSKIPOS_TURNOS_DESDE,
 *          fecha + terminal TakePOS (día completo).
 *
 * Cada reporte devuelve una estructura neutra que se pinta en HTML o en Excel:
 *   array('titulo' => '', 'secciones' => array(
 *       array('titulo' => '', 'columnas' => array(array('label', 'tipo')), 'filas' => array(...), 'total' => array(...) | null),
 *   ))
 * tipo: 'text', 'int', 'num' (2 decimales, 3 si los hay), 'usd', 'bs'.
 *
 * Reglas para leer los movimientos de stock del almacén del terminal en el día:
 *  - Existencia (EX)  = stock al inicio del día + ajustes "Existencia inicial…".
 *  - Reposición (REP) = entradas que no son de factura ni "Existencia inicial…" / "Ajuste…".
 *  - Venta directa    = líneas de tickets con pago. Crédito/delivery = tickets sin pagos
 *                       (+ salidas "Crédito…"). Regalías = salidas "Regal…". Dañadas = "Dañad…".
 *  - Por receta       = salidas de factura que no son el producto vendido (insumos de los servidos).
 *  - Cierre           = stock al final del día. La diferencia es el consumo/ajuste no registrado.
 *  - Conos            = "+N conos" en la descripción de la línea (o "N conos" en el movimiento).
 */

require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos_turnos.lib.php';

/**
 * Turnos disponibles (fecha + terminal con tickets TakePOS validados), los más recientes primero.
 *
 * @param DoliDB $db
 * @param int    $limit
 * @return array<array{fecha:string,terminal:string,label:string}>
 */
function sabrooskiposReporteTurnos($db, $limit = 60)
{
	global $conf;
	require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos_turnos.lib.php';
	$out = array();
	// v1.16: los turnos son las sesiones de caja de POSPlus (desde SABROOSKIPOS_TURNOS_DESDE).
	$porTurno = array();
	if (sabrooskiposPosplusActivo($db)) {
		$desde = getDolGlobalString('SABROOSKIPOS_TURNOS_DESDE');
		$resql = $db->query("SELECT DISTINCT DATE(date_open) AS f, terminal FROM ".MAIN_DB_PREFIX."posplus_cashsession WHERE entity = ".((int) $conf->entity)
			.($desde !== '' ? " AND date_open >= '".$db->escape($desde)." 00:00:00'" : "")." ORDER BY f DESC, terminal LIMIT ".((int) $limit));
		$dias = array();
		while ($resql && ($o = $db->fetch_object($resql))) {
			$dias[] = array((string) $o->f, (string) $o->terminal);
		}
		foreach ($dias as $d) {
			$name = getDolGlobalString('TAKEPOS_TERMINAL_NAME_'.$d[1], 'Terminal '.$d[1]);
			foreach (array_reverse(sabrooskiposTurnos($db, $d[1], $d[0])) as $t) {
				$out[] = array('fecha' => $d[0], 'terminal' => $d[1], 'sesion' => $t['id'],
					'label' => dol_print_date(dol_stringtotime($d[0].' 12:00:00'), 'day').' · '.$name.' · '.$t['label']);
			}
			$porTurno[$d[0].'|'.$d[1]] = 1;
		}
	}
	// Días sin sesiones (o anteriores a los turnos): por fecha y terminal, como antes.
	$sql = "SELECT f.datef, f.pos_source, COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."facture f";
	$sql .= " WHERE f.entity = ".((int) $conf->entity)." AND f.module_source = 'takepos' AND f.fk_statut >= 1";
	$sql .= " GROUP BY f.datef, f.pos_source ORDER BY f.datef DESC, f.pos_source LIMIT ".((int) $limit);
	$resql = $db->query($sql);
	while ($resql && ($o = $db->fetch_object($resql))) {
		if (isset($porTurno[$o->datef.'|'.$o->pos_source]) || sabrooskiposModoTurnos($db, (string) $o->datef) && sabrooskiposTurnos($db, (string) $o->pos_source, (string) $o->datef)) {
			continue;
		}
		$name = getDolGlobalString('TAKEPOS_TERMINAL_NAME_'.$o->pos_source, 'Terminal '.$o->pos_source);
		$out[] = array('fecha' => $o->datef, 'terminal' => (string) $o->pos_source, 'sesion' => 0,
			'label' => dol_print_date($db->jdate($o->datef), 'day').' · '.$name.' ('.$o->nb.' tickets)');
	}
	usort($out, function ($a, $b) {
		return strcmp($b['fecha'], $a['fecha']) ?: ($b['sesion'] - $a['sesion']);
	});
	return array_slice($out, 0, $limit);
}

/**
 * Carga todos los datos del turno una sola vez (los cuatro reportes salen de aquí).
 *
 * @param DoliDB $db
 * @param string $fecha     'YYYY-MM-DD'
 * @param string $terminal
 * @param int    $turno     sesión de caja POSPlus (v1.16); 0 = día completo
 * @return array
 */
function sabrooskiposReporteDatos($db, $fecha, $terminal, $turno = 0)
{
	global $conf;
	require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos_turnos.lib.php';
	$entity = (int) $conf->entity;
	$wh = getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$terminal);
	// Ventana: el turno (sesión de caja), el día por turnos o el día completo de antes (legacy).
	$v = sabrooskiposTurnoVentana($db, $terminal, $fecha, (int) $turno);
	$d1 = $v['t1'];
	$en = function ($col) use ($db, $v) {
		return sabrooskiposSqlEnTurno($db, $v, $col);
	};

	$data = array('fecha' => $fecha, 'terminal' => $terminal, 'almacen' => $wh,
		'terminal_nombre' => getDolGlobalString('TAKEPOS_TERMINAL_NAME_'.$terminal, 'Terminal '.$terminal),
		'turno' => (int) $v['id'], 'turno_label' => $v['label'], 'turno_abierto' => $v['abierta'], 'ventana' => $v,
		'tickets' => array(), 'lineas' => array(), 'pagos' => array(), 'productos' => array(), 'tasa' => 0,
		'hora_inicio' => '', 'hora_cierre' => '', 'usuarios' => array());

	// Tickets del turno, con lo pagado. Por turno: los validados dentro de la ventana. La hora
	// de validación es la de su salida de stock (date_valid y datef son solo el día; datec es
	// cuando se abrió el borrador; date_closing cambia si un crédito se cobra otro día).
	$horaTicket = "COALESCE((SELECT MIN(sm.datem) FROM ".MAIN_DB_PREFIX."stock_mouvement sm WHERE sm.origintype = 'facture' AND sm.fk_origin = f.rowid), f.date_closing, f.datec)";
	$sql = "SELECT f.rowid, f.ref, f.datec, f.total_ttc, f.note_private, s.nom AS cliente, u.firstname, u.lastname, u.login,";
	$sql .= " (SELECT COALESCE(SUM(pf.amount), 0) FROM ".MAIN_DB_PREFIX."paiement_facture pf WHERE pf.fk_facture = f.rowid) AS pagado";
	$sql .= " FROM ".MAIN_DB_PREFIX."facture f LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = f.fk_soc";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = f.fk_user_valid";
	$sql .= " WHERE f.entity = ".$entity." AND f.module_source = 'takepos' AND f.fk_statut >= 1";
	$sql .= " AND f.pos_source = '".$db->escape($terminal)."'";
	$sql .= ($v['legacy'] ? " AND f.datef = '".$db->escape($fecha)."'" : " AND f.datef >= '".$db->escape(substr($v['t0'], 0, 10))."' AND ".$en($horaTicket));
	$sql .= " ORDER BY f.datec, f.rowid";
	$resql = $db->query($sql);
	while ($resql && ($o = $db->fetch_object($resql))) {
		$credito = ((float) $o->pagado == 0 && (float) $o->total_ttc > 0);
		$data['tickets'][(int) $o->rowid] = array('ref' => $o->ref, 'hora' => substr($o->datec, 11, 5), 'total' => (float) $o->total_ttc,
			'credito' => $credito, 'cliente' => html_entity_decode((string) $o->cliente, ENT_QUOTES, 'UTF-8'), 'nota' => (string) $o->note_private);
		if ($data['hora_inicio'] === '') {
			$data['hora_inicio'] = substr($o->datec, 11, 5);
		}
		$data['hora_cierre'] = substr($o->datec, 11, 5);
		$uname = trim($o->firstname.' '.$o->lastname);
		$data['usuarios'][$uname !== '' ? $uname : (string) $o->login] = 1;
	}
	$ids = array_keys($data['tickets']);
	if (!$v['legacy']) {
		// Por turno, las horas son las de la caja (apertura y cierre X), no las de los tickets.
		$data['hora_inicio'] = substr($v['date_open'], 11, 5);
		$data['hora_cierre'] = ($v['abierta'] ? 'en curso' : substr($v['date_close'], 11, 5));
	}

	if ($ids) {
		// Líneas de venta.
		$sql = "SELECT d.rowid, d.fk_facture, d.fk_product, d.description, d.qty, d.remise_percent, d.total_ttc, d.total_ht, p.ref, p.label";
		$sql .= " FROM ".MAIN_DB_PREFIX."facturedet d LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = d.fk_product";
		$sql .= " WHERE d.fk_facture IN (".implode(',', $ids).") ORDER BY d.fk_facture, d.rang, d.rowid";
		$resql = $db->query($sql);
		while ($resql && ($o = $db->fetch_object($resql))) {
			$data['lineas'][] = array('rowid' => (int) $o->rowid, 'total_ht' => (float) $o->total_ht, 'fk_facture' => (int) $o->fk_facture, 'fk_product' => (int) $o->fk_product, 'ref' => (string) $o->ref,
				'label' => (string) $o->label, 'desc' => (string) $o->description, 'qty' => (float) $o->qty,
				'remise' => (float) $o->remise_percent, 'total' => (float) $o->total_ttc,
				'credito' => $data['tickets'][(int) $o->fk_facture]['credito']);
		}

		// Pagos con su movimiento bancario.
		$sql = "SELECT pf.fk_facture, p.datep, p.num_paiement, pf.amount, pf.multicurrency_amount, cp.code, cp.libelle, ba.label AS cuenta, ba.currency_code, b.amount AS bank_amount";
		$sql .= " FROM ".MAIN_DB_PREFIX."paiement_facture pf JOIN ".MAIN_DB_PREFIX."paiement p ON p.rowid = pf.fk_paiement";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_paiement cp ON cp.id = p.fk_paiement";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank b ON b.rowid = p.fk_bank LEFT JOIN ".MAIN_DB_PREFIX."bank_account ba ON ba.rowid = b.fk_account";
		$sql .= " WHERE pf.fk_facture IN (".implode(',', $ids).") ORDER BY p.datep, p.rowid";
		$resql = $db->query($sql);
		while ($resql && ($o = $db->fetch_object($resql))) {
			$foreign = ($o->currency_code && $o->currency_code != $conf->currency);
			$data['pagos'][] = array('fk_facture' => (int) $o->fk_facture, 'ticket' => $data['tickets'][(int) $o->fk_facture]['ref'],
				'hora' => substr($o->datep, 11, 5), 'num' => (string) $o->num_paiement, 'code' => (string) $o->code,
				'modo' => ($o->code == 'LIQ' ? 'Efectivo' : (string) $o->libelle), 'cuenta' => (string) $o->cuenta, 'usd' => (float) $o->amount,
				'bs' => ($foreign ? (float) ($o->multicurrency_amount ? $o->multicurrency_amount : $o->bank_amount) : 0), 'extranjera' => $foreign);
		}
	}

	// Tasa del día (VEF u otra moneda distinta de la principal): la última registrada hasta el cierre del día.
	$sql = "SELECT r.rate FROM ".MAIN_DB_PREFIX."multicurrency_rate r JOIN ".MAIN_DB_PREFIX."multicurrency m ON m.rowid = r.fk_multicurrency";
	$sql .= " WHERE m.entity = ".$entity." AND m.code <> '".$db->escape($conf->currency)."' AND r.date_sync <= '".$db->escape($d1)."'";
	$sql .= " ORDER BY r.date_sync DESC, r.rowid DESC LIMIT 1";
	$resql = $db->query($sql);
	if ($resql && ($o = $db->fetch_object($resql))) {
		$data['tasa'] = (float) $o->rate;
	}

	// Productos con stock o movimientos en el almacén del terminal, con su categoría de venta.
	if ($wh > 0) {
		// Ventana del turno (o del día): antes = existencia anterior, per = movimientos del turno.
		$per = $en('m.datem');
		$antes = sabrooskiposSqlAntesTurno($db, $v, 'm.datem');
		$sql = "SELECT p.rowid, p.ref, p.label, p.price_ttc, p.tosell,";
		$sql .= " COALESCE(SUM(CASE WHEN ".$antes." THEN m.value ELSE 0 END), 0) AS s0,";
		$sql .= " COALESCE(SUM(CASE WHEN m.datem <= '".$d1."' THEN m.value ELSE 0 END), 0) AS s1,";
		$sql .= " COALESCE(SUM(CASE WHEN ".$per." AND m.origintype <> 'facture' AND m.label LIKE 'Existencia inicial%' THEN m.value ELSE 0 END), 0) AS inicial,";
		$sql .= " COALESCE(SUM(CASE WHEN ".$per." AND COALESCE(m.origintype, '') <> 'facture' AND m.value > 0 AND m.label NOT LIKE 'Existencia inicial%' AND m.label NOT LIKE 'Ajuste%'"
			." AND m.label NOT LIKE 'Regal%' AND m.label NOT LIKE 'Dañad%' AND m.label NOT LIKE 'Devolución%' THEN m.value ELSE 0 END), 0) AS rep,";
		// Regalías y dañadas netas (una anulada entra en positivo con la misma etiqueta + "anulada").
		$sql .= " COALESCE(SUM(CASE WHEN ".$per." AND COALESCE(m.origintype, '') <> 'facture' AND m.label LIKE 'Regal%' THEN -m.value ELSE 0 END), 0) AS regalo,";
		$sql .= " COALESCE(SUM(CASE WHEN ".$per." AND COALESCE(m.origintype, '') <> 'facture' AND m.label LIKE 'Dañad%' THEN -m.value ELSE 0 END), 0) AS danadas,";
		$sql .= " COALESCE(SUM(CASE WHEN ".$per." AND COALESCE(m.origintype, '') <> 'facture' AND m.value < 0 AND m.label LIKE 'Crédito%' THEN -m.value ELSE 0 END), 0) AS credito_mov,";
		// Traspaso de turno (legacy, hasta v1.15): lo que pasó al camión siguiente era la existencia al cierre.
		$sql .= " COALESCE(SUM(CASE WHEN ".$per." AND m.label LIKE 'Traspaso de turno%' THEN -m.value ELSE 0 END), 0) AS traspaso,";
		// v1.16: la devolución a Principal del cierre del día también era la existencia al cierre.
		$sql .= " COALESCE(SUM(CASE WHEN ".$per." AND m.label LIKE 'Devolución a Principal%' THEN -m.value ELSE 0 END), 0) AS devol,";
		// Salidas por los tickets del turno (producto vendido o insumo de su receta).
		$sql .= " COALESCE(SUM(CASE WHEN m.origintype = 'facture' AND m.fk_origin IN (".($ids ? implode(',', $ids) : '0').") THEN -m.value ELSE 0 END), 0) AS fac,";
		$sql .= " GROUP_CONCAT(CASE WHEN ".$per." AND m.label LIKE 'Regal%' AND m.value < 0"
			." AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."stock_mouvement ma WHERE ma.inventorycode = CONCAT(m.inventorycode, '-A')) THEN m.label END SEPARATOR ' | ') AS regalo_labels";
		$sql .= " FROM ".MAIN_DB_PREFIX."product p JOIN ".MAIN_DB_PREFIX."stock_mouvement m ON m.fk_product = p.rowid AND m.fk_entrepot = ".((int) $wh);
		$sql .= " WHERE p.entity = ".$entity;
		$sql .= " GROUP BY p.rowid, p.ref, p.label, p.price_ttc, p.tosell";
		$resql = $db->query($sql);
		while ($resql && ($o = $db->fetch_object($resql))) {
			$data['productos'][(int) $o->rowid] = array('ref' => $o->ref, 'label' => $o->label, 'precio' => (float) $o->price_ttc, 'tosell' => (int) $o->tosell,
				'ex' => (float) $o->s0 + (float) $o->inicial, 'anterior' => (float) $o->s0, 'inicial' => (float) $o->inicial, 'rep' => (float) $o->rep, 'regalo' => (float) $o->regalo, 'danadas' => (float) $o->danadas,
				'credito_mov' => (float) $o->credito_mov, 'fac' => (float) $o->fac, 'cierre' => (float) $o->s1 + (float) $o->traspaso + (float) $o->devol, 'traspaso' => (float) $o->traspaso, 'devol' => (float) $o->devol, 'regalo_conos' => sabrooskiposConos((string) $o->regalo_labels, true),
				'categoria' => '', 'vd' => 0, 'vd_conos' => 0, 'vd_total' => 0, 'del' => 0, 'del_conos' => 0);
		}
	}
	// Productos vendidos sin movimiento de stock (hechos al momento: especiales, adicionales).
	foreach ($data['lineas'] as $l) {
		if ($l['fk_product'] > 0 && !isset($data['productos'][$l['fk_product']])) {
			$data['productos'][$l['fk_product']] = array('ref' => $l['ref'], 'label' => $l['label'], 'precio' => 0, 'tosell' => 1, 'ex' => 0, 'rep' => 0,
				'regalo' => 0, 'danadas' => 0, 'credito_mov' => 0, 'cierre' => 0, 'regalo_conos' => 0, 'categoria' => '', 'sin_stock' => 1,
				'vd' => 0, 'vd_conos' => 0, 'vd_total' => 0, 'del' => 0, 'del_conos' => 0);
		}
	}
	// Regalías y dañadas registradas (carga/bajas.php). Un servido no tiene stock propio
	// (baja por receta): se cuenta aquí. Lo que tiene stock ya lo contó su movimiento.
	$data['bajas'] = array();
	if ($wh > 0) {
		require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos_bajas.lib.php';
		$data['bajas'] = ($v['legacy'] ? sabrooskiposBajasDia($db, $wh, $fecha) : sabrooskiposBajasTurno($db, $wh, $v['ids']));
		foreach ($data['bajas'] as $b) {
			$r = sabrooskiposReceta($db, $b['fk_product']);
			if (!$r['kit'] && $r['mix_g'] <= 0) {
				continue;
			}
			if (!isset($data['productos'][$b['fk_product']])) {
				$data['productos'][$b['fk_product']] = array('ref' => $b['ref'], 'label' => $b['label'], 'precio' => 0, 'tosell' => 1, 'ex' => 0, 'rep' => 0,
					'regalo' => 0, 'danadas' => 0, 'credito_mov' => 0, 'cierre' => 0, 'regalo_conos' => 0, 'categoria' => '', 'sin_stock' => 1,
					'vd' => 0, 'vd_conos' => 0, 'vd_total' => 0, 'del' => 0, 'del_conos' => 0);
			}
			$data['productos'][$b['fk_product']][$b['tipo'] == 'regalia' ? 'regalo' : 'danadas'] += $b['qty'];
		}
	}

	foreach ($data['lineas'] as $l) {
		if ($l['fk_product'] <= 0) {
			continue;
		}
		$p = &$data['productos'][$l['fk_product']];
		if ($l['credito']) {
			$p['del'] += $l['qty'];
			$p['del_conos'] += sabrooskiposConos($l['desc']) * $l['qty'];
		} else {
			$p['vd'] += $l['qty'];
			$p['vd_conos'] += sabrooskiposConos($l['desc']) * $l['qty'];
			$p['vd_total'] += $l['total'];
		}
		unset($p);
	}

	// Categoría de venta de cada producto (la primera, por posición en el árbol).
	if ($data['productos']) {
		$sql = "SELECT cp.fk_product, c.label FROM ".MAIN_DB_PREFIX."categorie_product cp JOIN ".MAIN_DB_PREFIX."categorie c ON c.rowid = cp.fk_categorie";
		$sql .= " WHERE cp.fk_product IN (".implode(',', array_keys($data['productos'])).") ORDER BY c.fk_parent DESC, c.label";
		$resql = $db->query($sql);
		while ($resql && ($o = $db->fetch_object($resql))) {
			if ($data['productos'][(int) $o->fk_product]['categoria'] === '') {
				$data['productos'][(int) $o->fk_product]['categoria'] = $o->label;
			}
		}
	}
	return $data;
}

/**
 * Cuenta conos en un texto: "+4 conos" (descripción de línea) o "4 conos" (si $libre).
 *
 * @param string $txt
 * @param bool   $libre
 * @return int
 */
function sabrooskiposConos($txt, $libre = false)
{
	$n = 0;
	if (preg_match_all($libre ? '/(\d+)\s*conos/i' : '/\+\s*(\d+)\s*conos/i', $txt, $m)) {
		foreach ($m[1] as $v) {
			$n += (int) $v;
		}
	}
	return $n;
}

/**
 * Orden de las categorías en los reportes (igual que la planilla de inventario).
 *
 * @param string $cat
 * @return int
 */
function sabrooskiposOrdenCategoria($cat)
{
	$orden = array('Servido en camión', 'Especial domo', 'Bandejas 500 g', 'Sundae congelado', 'Empaquetado', 'Individual', 'Golosinas', 'Adicional', 'Topping', 'Sirope', 'Insumos');
	$i = array_search($cat, $orden);
	return ($i === false ? 99 : $i);
}

/**
 * Encabezado común: fecha, día, turno, horas, tasa, usuarios.
 *
 * @param array $data
 * @return array<array{0:string,1:string}>
 */
function sabrooskiposReporteEncabezado($data)
{
	$dias = array('Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado');
	$ts = dol_stringtotime($data['fecha'].' 12:00:00');
	$turno = $data['terminal_nombre'].' (terminal '.$data['terminal'].')';
	if (!empty($data['ventana']) && empty($data['ventana']['legacy'])) {
		$turno = $data['turno_label'].' · '.$data['terminal_nombre'].(!empty($data['turno_abierto']) ? ' · EN CURSO (datos parciales)' : '');
	}
	return array(
		array('Fecha', dol_print_date($ts, '%d/%m/%Y')),
		array('Día', $dias[(int) dol_print_date($ts, '%w')]),
		array('Turno', $turno),
		array('Hora de inicio / cierre', ($data['hora_inicio'] ?: '-').' / '.($data['hora_cierre'] ?: '-')),
		array('Tasa del día (Bs/$)', $data['tasa'] ? price($data['tasa'], 0, '', 1, -1, 2) : '-'),
		array('Usuario(s)', implode(', ', array_keys($data['usuarios']))),
	);
}

/**
 * Planilla "Camión congelados": bandejas y sundae congelado por sabor.
 *
 * @param array $data
 * @return array
 */
function sabrooskiposReporteCamion($data)
{
	$cols = array(array('Sabor', 'text'), array('EX', 'int'), array('REP', 'int'), array('TOTAL', 'int'), array('VD uds', 'int'), array('VD conos', 'int'),
		array('DEL uds', 'int'), array('DEL conos', 'int'), array('Regalo uds', 'int'), array('Regalo conos', 'int'), array('Dañadas', 'int'),
		array('Cierre', 'int'), array('Cierre calculado', 'int'), array('Diferencia', 'int'), array('Total conos', 'int'));
	$secciones = array();
	foreach (array('Bandejas 500 g' => array('SABOR – BANDEJAS', 'Bandeja '), 'Sundae congelado' => array('SABOR – SUNDAE (congelado)', 'Sundae congelado ')) as $cat => $def) {
		$filas = array();
		$tot = array_fill(0, count($cols), 0);
		$tot[0] = 'TOTAL';
		$prods = array_filter($data['productos'], function ($p) use ($cat) {
			return $p['categoria'] === $cat;
		});
		uasort($prods, function ($a, $b) {
			return strcmp($a['ref'], $b['ref']);
		});
		foreach ($prods as $p) {
			$sabor = (stripos($p['label'], $def[1]) === 0 ? substr($p['label'], strlen($def[1])) : $p['label']);
			$total = $p['ex'] + $p['rep'];
			$calc = $total - $p['vd'] - $p['del'] - $p['regalo'] - $p['danadas'];
			$row = array($sabor, $p['ex'], $p['rep'], $total, $p['vd'], $p['vd_conos'], $p['del'], $p['del_conos'], $p['regalo'], $p['regalo_conos'],
				$p['danadas'], $p['cierre'], $calc, $p['cierre'] - $calc, $p['vd_conos'] + $p['del_conos'] + $p['regalo_conos']);
			$filas[] = $row;
			for ($k = 1; $k < count($row); $k++) {
				$tot[$k] += $row[$k];
			}
		}
		$secciones[] = array('titulo' => $def[0], 'columnas' => $cols, 'filas' => $filas, 'total' => ($filas ? $tot : null));
	}
	return array('titulo' => 'HELADOS CONGELADOS – CAMIÓN (Bandejas y Sundae)', 'hoja' => 'Camión Congelados', 'secciones' => $secciones);
}

/**
 * Planilla "Pago móvil y puntos de venta".
 *
 * @param array $data
 * @return array
 */
function sabrooskiposReportePagoMovil($data)
{
	$tasa = $data['tasa'];
	$filas = array();
	$n = 0;
	$tbs = 0;
	$tusd = 0;
	$lotes = array();
	$otros = array();
	foreach ($data['pagos'] as $p) {
		if ($p['code'] == 'PMP') {
			$n++;
			$eq = ($tasa > 0 ? $p['bs'] / $tasa : $p['usd']);
			$filas[] = array($n, $p['num'], $p['bs'], ($p['usd'] > 0 ? $p['bs'] / $p['usd'] : 0), $eq, $p['hora'], $p['ticket']);
			$tbs += $p['bs'];
			$tusd += $eq;
		} elseif ($p['code'] == 'PDV1') {
			$lote = $p['num'] !== '' ? $p['num'] : 'Sin lote';
			$tipo = 'Otro';
			if (preg_match('/^Lote\s+(\S+)\s*·\s*(.+)$/u', $p['num'], $m)) {
				$lote = $m[1];
				$tipo = (stripos($m[2], 'déb') !== false || stripos($m[2], 'deb') !== false) ? 'Débito' : 'Crédito';
			}
			if (!isset($lotes[$lote])) {
				$lotes[$lote] = array('Crédito' => array(0, 0), 'Débito' => array(0, 0), 'Otro' => array(0, 0), 'hora' => '');
			}
			$lotes[$lote][$tipo][0]++;
			$lotes[$lote][$tipo][1] += $p['bs'];
			$lotes[$lote]['hora'] = $p['hora'];
		} else {
			$k = $p['modo'].($p['extranjera'] ? ' (Bs)' : ' ($)');
			if (!isset($otros[$k])) {
				$otros[$k] = array(0, 0, 0);
			}
			$otros[$k][0]++;
			$otros[$k][1] += ($p['extranjera'] ? $p['bs'] : 0);
			$otros[$k][2] += $p['usd'];
		}
	}
	$secciones = array();
	$secciones[] = array('titulo' => 'RELACIÓN DE PAGO MÓVIL',
		'columnas' => array(array('Nº', 'int'), array('Referencia', 'text'), array('Monto Bs', 'bs'), array('Tasa cobrada', 'num'), array('Equivalente $ (tasa del día)', 'usd'), array('Hora', 'text'), array('Ticket', 'text')),
		'filas' => $filas, 'total' => ($filas ? array('TOTAL P/M', '', $tbs, '', $tusd, '', '') : null));

	$fl = array();
	$tot = array('TOTAL', '', 0, 0, 0, 0, 0, 0, 0);
	foreach ($lotes as $lote => $v) {
		$nt = $v['Crédito'][0] + $v['Débito'][0] + $v['Otro'][0];
		$bs = $v['Crédito'][1] + $v['Débito'][1] + $v['Otro'][1];
		$row = array($lote, $v['hora'], $v['Crédito'][0], $v['Crédito'][1], $v['Débito'][0], $v['Débito'][1], $nt, $bs, ($tasa > 0 ? $bs / $tasa : 0));
		$fl[] = $row;
		for ($k = 2; $k < 9; $k++) {
			$tot[$k] += $row[$k];
		}
	}
	$secciones[] = array('titulo' => 'CIERRES DE PUNTO DE VENTA',
		'columnas' => array(array('Lote', 'text'), array('Última trans.', 'text'), array('Crédito (trans.)', 'int'), array('Crédito Bs', 'bs'), array('Débito (trans.)', 'int'),
			array('Débito Bs', 'bs'), array('Total trans.', 'int'), array('Total Bs', 'bs'), array('Equivalente $', 'usd')),
		'filas' => $fl, 'total' => ($fl ? $tot : null));

	$fo = array();
	foreach ($otros as $k => $v) {
		$fo[] = array($k, $v[0], $v[1], $v[2]);
	}
	$secciones[] = array('titulo' => 'OTROS COBROS', 'columnas' => array(array('Forma de pago', 'text'), array('Nº pagos', 'int'), array('Monto Bs', 'bs'), array('Monto $', 'usd')),
		'filas' => $fo, 'total' => null);

	$obs = array();
	foreach ($data['lineas'] as $l) {
		if ($l['remise'] > 0) {
			$obs[] = array($data['tickets'][$l['fk_facture']]['ref'], $l['label'].' – '.price($l['remise']).'% de descuento'.($l['desc'] ? ' ('.$l['desc'].')' : ''));
		}
	}
	foreach ($data['tickets'] as $t) {
		if ($t['credito']) {
			$obs[] = array($t['ref'], 'Venta a crédito'.($t['cliente'] ? ' – '.$t['cliente'] : '').' por '.price($t['total'], 0, '', 1, -1, 2).' $');
		}
	}
	$conBaja = array();
	foreach ($data['bajas'] as $b) {
		$conBaja[$b['fk_product']] = 1;
		$obs[] = array('', ($b['tipo'] == 'regalia' ? 'Regalía' : 'Dañada').': '.$b['label'].' × '.price2num($b['qty']).($b['sabores_txt'] ? ' ('.$b['sabores_txt'].')' : '')
			.' – '.$b['motivo'].' (autorizó '.$b['autoriza'].', costo '.price($b['costo'], 0, '', 1, -1, 2).' $)');
	}
	foreach ($data['productos'] as $pid => $p) {
		if ($p['regalo'] > 0 && !isset($conBaja[$pid])) {
			$obs[] = array('', 'Regalía: '.$p['label'].' × '.$p['regalo']);
		}
	}
	$secciones[] = array('titulo' => 'OBSERVACIONES', 'columnas' => array(array('Ticket', 'text'), array('Detalle', 'text')), 'filas' => $obs, 'total' => null);

	return array('titulo' => 'RELACIÓN DE PAGO MÓVIL Y PUNTOS DE VENTA', 'hoja' => 'Pago Móvil', 'secciones' => $secciones);
}

/**
 * Agrupa un producto vendido como en la planilla de especificaciones.
 *
 * @param array $p
 * @return string
 */
function sabrooskiposGrupoVenta($p)
{
	$cat = $p['categoria'];
	if ($cat == 'Especial domo') {
		return 'Vaso Domo';
	}
	if ($cat == 'Bandejas 500 g') {
		return 'Bandeja';
	}
	if ($cat == 'Sundae congelado') {
		return 'Sundae congelado';
	}
	if (in_array($cat, array('Adicional', 'Topping', 'Sirope'))) {
		return 'Adicionales';
	}
	return $p['label'];
}

/**
 * Planilla "Especificaciones de ventas" + resumen de cierre.
 *
 * @param array $data
 * @return array
 */
function sabrooskiposReporteEspecificaciones($data)
{
	$grupos = array();
	$credito = array();
	foreach ($data['productos'] as $p) {
		$g = sabrooskiposGrupoVenta($p);
		$o = sabrooskiposOrdenCategoria($p['categoria']);
		if ($p['vd'] > 0) {
			if (!isset($grupos[$g])) {
				$grupos[$g] = array('o' => $o, 'ref' => $p['ref'], 'uds' => 0, 'total' => 0);
			}
			$grupos[$g]['uds'] += $p['vd'];
			$grupos[$g]['total'] += $p['vd_total'];
		}
		if ($p['del'] > 0) {
			$credito[] = $p;
		}
	}
	uasort($grupos, function ($a, $b) {
		return ($a['o'] - $b['o']) ?: strcmp($a['ref'], $b['ref']);
	});
	$filas = array();
	$tventas = 0;
	foreach ($grupos as $g => $v) {
		$filas[] = array($g, $v['uds'], ($v['uds'] ? $v['total'] / $v['uds'] : 0), $v['total']);
		$tventas += $v['total'];
	}
	$secciones = array();
	$secciones[] = array('titulo' => 'VENTAS POR PRODUCTO (cobradas)', 'columnas' => array(array('Descripción', 'text'), array('Uds.', 'int'), array('P.U. ($)', 'usd'), array('Total $', 'usd')),
		'filas' => $filas, 'total' => array('TOTAL VENTAS ($)', '', '', $tventas));

	// Ventas a crédito (delivery).
	$fc = array();
	$tc = 0;
	foreach ($data['tickets'] as $tid => $t) {
		if ($t['credito']) {
			$det = array();
			foreach ($data['lineas'] as $l) {
				if ($l['fk_facture'] == $tid) {
					$det[] = ($l['qty'] + 0).' × '.$l['label'].($l['desc'] ? ' ('.$l['desc'].')' : '');
				}
			}
			$fc[] = array($t['ref'], $t['hora'], $t['cliente'], implode('; ', $det), $t['total']);
			$tc += $t['total'];
		}
	}
	$secciones[] = array('titulo' => 'VENTAS A CRÉDITO (delivery, por cobrar)', 'columnas' => array(array('Ticket', 'text'), array('Hora', 'text'), array('Cliente', 'text'), array('Detalle', 'text'), array('Total $', 'usd')),
		'filas' => $fc, 'total' => ($fc ? array('TOTAL', '', '', '', $tc) : null));

	// Resumen de cierre.
	$tasa = $data['tasa'];
	$sum = array('EFBS' => 0, 'LIQ' => 0, 'PMP' => 0, 'PDV1' => 0, 'otros_bs' => 0, 'otros_usd' => 0);
	foreach ($data['pagos'] as $p) {
		if (isset($sum[$p['code']])) {
			$sum[$p['code']] += ($p['extranjera'] ? $p['bs'] : $p['usd']);
		} elseif ($p['extranjera']) {
			$sum['otros_bs'] += $p['bs'];
		} else {
			$sum['otros_usd'] += $p['usd'];
		}
	}
	$totbs = $sum['EFBS'] + $sum['PMP'] + $sum['PDV1'] + $sum['otros_bs'];
	$totusd = $sum['LIQ'] + $sum['otros_usd'];
	$recaudado = ($tasa > 0 ? $totbs / $tasa : 0) + $totusd;
	$fr = array(
		array('Total Bs (efectivo)', $sum['EFBS'], ''),
		array('Total $ (efectivo)', '', $sum['LIQ']),
		array('Total P/M (pago móvil)', $sum['PMP'], ''),
		array('Total P/Vta (puntos)', $sum['PDV1'], ''),
	);
	if ($sum['otros_bs'] || $sum['otros_usd']) {
		$fr[] = array('Otras formas de pago', $sum['otros_bs'] ?: '', $sum['otros_usd'] ?: '');
	}
	$fr[] = array('Total Bs recaudado', $totbs, '');
	$fr[] = array('Total ventas en $ (Bs/tasa + $)', '', $recaudado);
	$fr[] = array('Ventas por productos ($)', '', $tventas);
	$fr[] = array('Recaudado − ventas por productos ($) (positivo = sobrante)', '', $recaudado - $tventas);
	$secciones[] = array('titulo' => 'RESUMEN DE CIERRE (tasa '.($tasa ? price($tasa, 0, '', 1, -1, 2) : '-').')', 'columnas' => array(array('Concepto', 'text'), array('Bs', 'bs'), array('$', 'usd')),
		'filas' => $fr, 'total' => null);

	return array('titulo' => 'ESPECIFICACIONES DE VENTAS', 'hoja' => 'Especificaciones Ventas', 'secciones' => $secciones);
}

/**
 * Planilla "Inventario de ventas": todos los productos del almacén del terminal.
 * Bandejas y sundae congelado se suman en un renglón cada uno (el detalle por sabor
 * está en la planilla del camión).
 *
 * @param array $data
 * @return array
 */
function sabrooskiposReporteInventario($data)
{
	global $db;
	require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos_recetas.lib.php';
	// Topping y sirope a granel (INS-TOPG / INS-SIRG) tienen su renglón. Las variedades viejas
	// (de antes del granel) se suman en un renglón por grupo; pirulín y oreo (uds), en otro.
	$gr = array_flip(array_filter(sabrooskiposGranelIds($db)));
	$grupoDe = array();
	$resql = $db->query("SELECT cp.fk_product, c.label, COALESCE(e.unidad_control, 'g') AS u FROM ".MAIN_DB_PREFIX."categorie_product cp"
		." JOIN ".MAIN_DB_PREFIX."categorie c ON c.rowid = cp.fk_categorie LEFT JOIN ".MAIN_DB_PREFIX."product_extrafields e ON e.fk_object = cp.fk_product"
		." WHERE c.label IN ('Sirope', 'Topping')");
	while ($resql && ($o = $db->fetch_object($resql))) {
		if (!isset($gr[(int) $o->fk_product])) {
			$grupoDe[(int) $o->fk_product] = ($o->u == 'uds' ? 'Pirulín y oreo (uds)' : ($o->label == 'Sirope' ? 'Siropes (g)' : 'Toppings (g)'));
		}
	}
	$rows = array();
	foreach ($data['productos'] as $pid => $p) {
		$cat = $p['categoria'];
		// Producto de receta con stock propio viejo en el móvil (de antes de las recetas): al
		// venderlo baja la receta, no él, así que se pinta como hecho al momento.
		if (empty($p['sin_stock']) && $p['vd'] + $p['del'] > 0 && (isset($p['fac']) ? $p['fac'] : 0) < $p['vd'] + $p['del'] - 0.0001) {
			$rec = sabrooskiposReceta($db, $pid);
			$p['sin_stock'] = ($rec['kit'] || $rec['mix_g'] > 0) ? 1 : 0;
		}
		$key = ($cat == 'Bandejas 500 g' ? 'Bandeja' : ($cat == 'Sundae congelado' ? 'Sundae congelado' : $p['ref']));
		if (isset($grupoDe[$pid])) {
			$key = $grupoDe[$pid];
		}
		if (!isset($rows[$key])) {
			$rows[$key] = array('o' => sabrooskiposOrdenCategoria($cat), 'ref' => $p['ref'], 'label' => ($key == $p['ref'] ? $p['label'] : $key),
				'venta' => ($p['tosell'] && !in_array($cat, array('Topping', 'Sirope', 'Insumos'))), 'precio' => $p['precio'],
				'ex' => 0, 'rep' => 0, 'vd' => 0, 'vd_total' => 0, 'regalo' => 0, 'credito' => 0, 'danadas' => 0, 'receta' => 0, 'cierre' => 0,
				'sin_stock' => !empty($p['sin_stock']), 'pid' => 0);
		}
		$r = &$rows[$key];
		$r['pid'] = $r['pid'] ?: $pid;
		// Lo que salió por factura y no es el propio producto vendido: insumos de una receta.
		$r['receta'] += max(0, (isset($p['fac']) ? $p['fac'] : 0) - $p['vd'] - $p['del']);
		$r['ex'] += $p['ex'];
		$r['rep'] += $p['rep'];
		$r['vd'] += $p['vd'];
		$r['vd_total'] += $p['vd_total'];
		$r['regalo'] += $p['regalo'];
		$r['credito'] += $p['del'] + $p['credito_mov'];
		$r['danadas'] += $p['danadas'];
		$r['cierre'] += $p['cierre'];
		unset($r);
	}
	// Lo que debieron gastar las ventas según la receta y no se descuenta al vender (pirulín y
	// oreo; topping y sirope solo si aún no están a granel).
	$tc = sabrooskiposTeoricoConteo($data);
	foreach (array('Siropes (g)' => 'sirope', 'Toppings (g)' => 'topping', 'Pirulín y oreo (uds)' => 'piru') as $k => $c) {
		if (isset($rows[$k])) {
			$rows[$k]['receta'] += $tc[$c];
		}
	}
	uasort($rows, function ($a, $b) {
		return ($a['o'] - $b['o']) ?: strcmp($a['ref'], $b['ref']);
	});
	// El mix se lleva, se carga y se cuenta en gramos (v1.15; antes en kg): aquí también en g.
	$esMix = array();
	$resql = $db->query("SELECT cp.fk_product FROM ".MAIN_DB_PREFIX."categorie_product cp JOIN ".MAIN_DB_PREFIX."categorie c ON c.rowid = cp.fk_categorie WHERE c.label = 'Mix'");
	while ($resql && ($o = $db->fetch_object($resql))) {
		$esMix[(int) $o->fk_product] = 1;
	}
	$filas = array();
	$tventas = 0;
	foreach ($rows as $r) {
		if (isset($esMix[$r['pid']]) && !$r['sin_stock']) {
			$r['label'] .= ' (g)';
		}
		// Variedades viejas de topping/sirope (antes del granel) sin nada en el turno: no se muestran.
		if (in_array($r['label'], array('Toppings (g)', 'Siropes (g)'))
			&& abs($r['ex']) + abs($r['rep']) + abs($r['cierre']) + abs($r['receta']) + abs($r['regalo']) + abs($r['danadas']) < 0.0001) {
			continue;
		}
		$total = $r['ex'] + $r['rep'];
		$auto = $r['regalo'] + $r['credito'] + $r['danadas'];
		$segun = $total - $r['cierre'];
		$registradas = $r['vd'] + $auto + $r['receta'];
		$pu = ($r['vd'] ? $r['vd_total'] / $r['vd'] : ($r['venta'] ? $r['precio'] : ''));
		if ($r['sin_stock']) {
			// Hecho al momento (servidos por receta, especiales, adicionales): solo la venta; lo
			// que gastó sale en los insumos de abajo (columna "Por receta").
			$rec = sabrooskiposReceta($db, $r['pid']);
			$suf = ($rec['kit'] || $rec['mix_g'] > 0) ? ' (por receta)' : ' (sin stock propio)';
			$filas[] = array($r['label'].$suf, '', '', '', $r['vd'], $pu, $r['vd_total'], $r['regalo'] ?: '', $r['credito'] ?: '', $r['danadas'] ?: '', ($auto ?: ''), '', '', '', '', '');
		} else {
			$filas[] = array($r['label'], $r['ex'], $r['rep'], $total, $r['vd'], $pu,
				$r['vd_total'], $r['regalo'], $r['credito'], $r['danadas'], $auto, $r['receta'], $r['cierre'], $segun, $registradas,
				(abs($segun - $registradas) < 0.00005 ? 0 : round($segun - $registradas, 4)));
		}
		$tventas += $r['vd_total'];
	}
	$cols = array(array('Descripción', 'text'), array('Existencia', 'num'), array('Reposición', 'num'), array('Total', 'num'), array('Ventas uds', 'num'),
		array('P. Unit ($)', 'usd'), array('Total ventas ($)', 'usd'), array('Regalías', 'num'), array('Crédito', 'num'), array('Dañadas', 'num'),
		array('Total autoconsumo', 'num'), array('Por receta', 'num'), array('Existencia al cierre', 'num'), array('Salidas según cierre', 'num'), array('Salidas registradas', 'num'), array('Diferencia', 'num'));
	return array('titulo' => 'SABROOSKI – INVENTARIO DE VENTAS', 'hoja' => 'Inventario Ventas',
		'nota' => '"Por receta" = lo que gastaron las ventas según la receta (conos, servilletas, mix, topping y sirope a granel…, con los adicionales). '
			.'La diferencia es la merma: lo que falta según el conteo del cierre. Pirulín y Oreo no bajan al vender: su "Por receta" es lo que lleva lo vendido.',
		'secciones' => array(array('titulo' => 'Almacén del terminal', 'columnas' => $cols, 'filas' => $filas,
			'total' => array('TOTAL VENTAS ($)', '', '', '', '', '', $tventas, '', '', '', '', '', '', '', '', ''))));
}

/**
 * Formatea un valor para HTML según el tipo de columna.
 *
 * @param mixed  $v
 * @param string $tipo
 * @return string
 */
function sabrooskiposFmt($v, $tipo)
{
	if ($v === '' || $v === null) {
		return '';
	}
	if ($tipo == 'text') {
		return dol_escape_htmltag((string) $v);
	}
	$v = (float) $v;
	if ($tipo == 'int') {
		return price($v, 0, '', 1, -1, (abs($v - round($v)) > 0.0001 ? 2 : 0));
	}
	if (abs($v) < 0.00005) {
		$v = 0.0; // sin "-0" por restos de decimales
	}
	// 2 decimales; 3 si hacen falta (kg de mix: 0,172)
	return price($v, 0, '', 1, -1, ($tipo == 'num' && abs($v * 100 - round($v * 100)) > 0.0001 ? 3 : 2));
}

/**
 * Pinta un reporte en HTML.
 *
 * @param array $rep
 * @param array $enc  Encabezado (pares)
 * @return string
 */
function sabrooskiposReporteHtml($rep, $enc)
{
	$h = '<div class="sbr-report"><h3 class="sbr-title">'.dol_escape_htmltag($rep['titulo']).'</h3>';
	$h .= '<div class="sbr-head">';
	foreach ($enc as $kv) {
		$h .= '<span><b>'.dol_escape_htmltag($kv[0]).':</b> '.dol_escape_htmltag($kv[1]).'</span>';
	}
	$h .= '</div>';
	if (!empty($rep['nota'])) {
		$h .= '<div class="opacitymedium small">'.dol_escape_htmltag($rep['nota']).'</div>';
	}
	foreach ($rep['secciones'] as $s) {
		$h .= '<div class="div-table-responsive"><table class="noborder centpercent sbr-table">';
		$h .= '<tr class="liste_titre"><th colspan="'.count($s['columnas']).'">'.dol_escape_htmltag($s['titulo']).'</th></tr>';
		$h .= '<tr class="liste_titre">';
		foreach ($s['columnas'] as $c) {
			$h .= '<th class="'.($c[1] == 'text' ? 'left' : 'right').'">'.dol_escape_htmltag($c[0]).'</th>';
		}
		$h .= '</tr>';
		if (!count($s['filas'])) {
			$h .= '<tr class="oddeven"><td colspan="'.count($s['columnas']).'" class="opacitymedium">Sin datos.</td></tr>';
		}
		foreach ($s['filas'] as $f) {
			$h .= '<tr class="oddeven">';
			foreach ($s['columnas'] as $k => $c) {
				$v = isset($f[$k]) ? $f[$k] : '';
				$cls = ($c[1] == 'text' ? 'left' : 'right nowraponall');
				if ($c[0] == 'Diferencia' && $v !== '' && abs((float) $v) > 0.0001) {
					$cls .= ' sbr-diff';
				}
				$h .= '<td class="'.$cls.'">'.sabrooskiposFmt($v, $c[1]).'</td>';
			}
			$h .= '</tr>';
		}
		if (!empty($s['total'])) {
			$h .= '<tr class="liste_total">';
			foreach ($s['columnas'] as $k => $c) {
				$v = isset($s['total'][$k]) ? $s['total'][$k] : '';
				$h .= '<td class="'.($c[1] == 'text' ? 'left' : 'right nowraponall').'">'.sabrooskiposFmt($v, $k == 0 ? 'text' : $c[1]).'</td>';
			}
			$h .= '</tr>';
		}
		$h .= '</table></div><br>';
	}
	$h .= '</div>';
	return $h;
}

/**
 * Descarga uno o varios reportes como .xlsx (una hoja por reporte).
 *
 * @param array<array> $reportes
 * @param array        $enc
 * @param string       $filename
 * @return void
 */
function sabrooskiposReporteExcel($reportes, $enc, $filename)
{
	require_once DOL_DOCUMENT_ROOT.'/includes/phpoffice/phpspreadsheet/src/autoloader.php';
	require_once DOL_DOCUMENT_ROOT.'/includes/Psr/autoloader.php';
	require_once PHPEXCELNEW_PATH.'Spreadsheet.php';

	$book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
	$book->removeSheetByIndex(0);
	$fmt = array('int' => '#,##0', 'num' => '#,##0.00#', 'usd' => '#,##0.00', 'bs' => '#,##0.00'); // num: 3.er decimal si lo hay (kg de mix)
	$hdrFill = array('fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => array('rgb' => '6B3FA0'));

	foreach ($reportes as $rep) {
		$ws = $book->createSheet();
		$ws->setTitle(substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $rep['hoja']), 0, 31));
		$row = 1;
		$ws->setCellValue('A'.$row, $rep['titulo']);
		$ws->getStyle('A'.$row)->getFont()->setBold(true)->setSize(14);
		$row += 2;
		foreach ($enc as $kv) {
			$ws->setCellValue('A'.$row, $kv[0].':');
			$ws->setCellValue('B'.$row, $kv[1]);
			$ws->getStyle('A'.$row)->getFont()->setBold(true);
			$row++;
		}
		if (!empty($rep['nota'])) {
			$ws->setCellValue('A'.$row, $rep['nota']);
			$ws->getStyle('A'.$row)->getFont()->setItalic(true);
			$row++;
		}
		$maxcol = 1;
		foreach ($rep['secciones'] as $s) {
			$row++;
			$ws->setCellValue('A'.$row, $s['titulo']);
			$ws->getStyle('A'.$row)->getFont()->setBold(true)->setSize(12);
			$row++;
			$maxcol = max($maxcol, count($s['columnas']));
			foreach ($s['columnas'] as $k => $c) {
				$ws->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($k + 1).$row, $c[0]);
			}
			$range = 'A'.$row.':'.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($s['columnas'])).$row;
			$ws->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
			$ws->getStyle($range)->getFill()->applyFromArray($hdrFill);
			$ws->getStyle($range)->getAlignment()->setWrapText(true);
			$row++;
			$lines = $s['filas'];
			if (!empty($s['total'])) {
				$lines[] = $s['total'];
			}
			foreach ($lines as $li => $f) {
				foreach ($s['columnas'] as $k => $c) {
					$v = isset($f[$k]) ? $f[$k] : '';
					$cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($k + 1).$row;
					if ($c[1] != 'text' && $v !== '' && is_numeric($v)) {
						$ws->setCellValue($cell, (float) $v);
						$ws->getStyle($cell)->getNumberFormat()->setFormatCode($fmt[$c[1]]);
						if ($c[0] == 'Diferencia' && abs((float) $v) > 0.0001) {
							$ws->getStyle($cell)->getFill()->applyFromArray(array('fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => array('rgb' => 'F8CBAD')));
						}
					} else {
						$ws->setCellValueExplicit($cell, (string) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					}
				}
				if (!empty($s['total']) && $li == count($lines) - 1) {
					$ws->getStyle('A'.$row.':'.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($s['columnas'])).$row)->getFont()->setBold(true);
				}
				$row++;
			}
		}
		$ws->getColumnDimension('A')->setWidth(34);
		for ($k = 2; $k <= $maxcol; $k++) {
			$ws->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($k))->setWidth(14);
		}
	}
	$book->setActiveSheetIndex(0);

	header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
	header('Content-Disposition: attachment; filename="'.$filename.'"');
	header('Cache-Control: max-age=0');
	$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book);
	$writer->save('php://output');
}

/**
 * Gramos de sirope y topping y unidades de pirulín/oreo que debieron gastarse en el turno
 * según las recetas y los adicionales (ventas + regalías y dañadas). No incluye lo que ya
 * descuenta un kit (p. ej. el oreo del Especial Oreo): eso sale en sus movimientos.
 *
 * @param array $data
 * @return array{sirope:float,topping:float,piru:float}
 */
function sabrooskiposTeoricoConteo($data)
{
	global $db;
	require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos_recetas.lib.php';
	$t = array('sirope' => 0.0, 'topping' => 0.0, 'piru' => 0.0);
	$items = array();
	foreach ($data['lineas'] as $l) {
		if ($l['fk_product'] > 0) {
			$items[] = array($l['fk_product'], $l['qty']);
		}
	}
	// Regalías y dañadas: el sirope y topping no se mueven al registrarlas (se ven en el conteo).
	foreach ($data['bajas'] as $b) {
		$items[] = array($b['fk_product'], $b['qty']);
	}
	foreach ($items as $it) {
		$c = sabrooskiposConsumoLinea($db, $it[0], $it[1], '');
		$t['sirope'] += $c['sirope_g'];
		$t['topping'] += $c['topping_g'];
		$t['piru'] += $c['pirulin_oreo'];
	}
	return $t;
}

/**
 * Planilla "Insumos del móvil": por insumo, existencia + carga − cierre contado = consumo
 * real, contra el consumo teórico de las ventas (recetas). Siropes, toppings y
 * pirulín/oreo se controlan en total (el cliente elige el tipo en la barra).
 *
 * @param array $data
 * @return array
 */
function sabrooskiposReporteInsumos($data)
{
	global $db;
	require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos_recetas.lib.php';
	$pre = MAIN_DB_PREFIX;
	$wh = (int) $data['almacen'];

	// Consumo teórico por insumo y totales de conteo (sirope, topping, pirulín/oreo).
	$sabores = array();
	foreach (array_unique(array_map(function ($l) {
		return $l['fk_facture'];
	}, $data['lineas'])) as $fid) {
		$sabores += sabrooskiposSaboresFactura($db, $fid);
	}
	$teo = array();
	$mixSinSabor = 0;
	foreach ($data['lineas'] as $l) {
		if ($l['fk_product'] <= 0) {
			continue;
		}
		$s = isset($sabores[$l['rowid']]) ? $sabores[$l['rowid']] : '';
		$c = sabrooskiposConsumoLinea($db, $l['fk_product'], $l['qty'], $s);
		foreach ($c['insumos'] as $pid => $q) {
			$teo[$pid] = (isset($teo[$pid]) ? $teo[$pid] : 0) + $q;
		}
		$r = sabrooskiposReceta($db, $l['fk_product']);
		if ($r['mix_g'] > 0 && $s === '') {
			$mixSinSabor += $r['mix_g'] * $l['qty'];
		}
	}
	$tc = sabrooskiposTeoricoConteo($data);
	$teoSirope = $tc['sirope'];
	$teoTopping = $tc['topping'];
	$teoPiru = $tc['piru'];

	// Descontado por las ventas (movimientos con origen factura) en el día.
	$ventasMov = array();
	if ($wh > 0) {
		$resql = $db->query("SELECT fk_product, SUM(value) AS v FROM ".$pre."stock_mouvement WHERE fk_entrepot = ".$wh." AND origintype = 'facture'"
			." AND ".sabrooskiposSqlEnTurno($db, $data['ventana'], 'datem')." GROUP BY fk_product");
		while ($resql && ($o = $db->fetch_object($resql))) {
			$ventasMov[(int) $o->fk_product] = -(float) $o->v;
		}
	}

	$datos = sabrooskiposRecetasLeer();
	$grupos = $datos ? sabrooskiposCargaGrupos($db, $datos) : array();
	// Existencia anterior = lo que había antes del día; Carga inicial = "Existencia inicial…"
	// (carga de la mañana); Reposición = cargas durante el turno. Por turnos, la existencia anterior del
	// PM es lo que dejó el AM tras su conteo.
	$cols = array(array('Insumo', 'text'), array('Unidad', 'text'), array('Existencia anterior', 'num'), array('Carga inicial', 'num'), array('Reposición', 'num'), array('Total', 'num'),
		array('Regalías y dañadas', 'num'), array('Cierre', 'num'), array('Consumo real', 'num'), array('Descontado por ventas', 'num'),
		array('Consumo teórico', 'num'), array('Diferencia', 'num'));
	$secciones = array();
	foreach ($grupos as $g) {
		if ($g['titulo'] == 'Congelados') {
			continue; // por unidad: ver "Camión congelados" e "Inventario de ventas"
		}
		$filas = array();
		// Totales de lo que se controla por conteo, por unidad: existencia anterior, carga inicial,
		// reposición, bajas, cierre, real, teórico del kit y descontado por ventas.
		$conteo = array('g' => array_fill(0, 8, 0), 'uds' => array_fill(0, 8, 0));
		foreach ($g['items'] as $it) {
			$p = isset($data['productos'][$it['id']]) ? $data['productos'][$it['id']] : null;
			$f = $it['factor'] > 0 ? $it['factor'] : 1;
			$ex = $p ? $p['ex'] : 0;
			$ant = $p ? (isset($p['anterior']) ? $p['anterior'] : $ex) : 0;
			$ini = $ex - $ant;
			$rep = $p ? $p['rep'] : 0;
			$baja = $p ? $p['regalo'] + $p['danadas'] : 0;
			$cierre = $p ? $p['cierre'] : 0;
			$real = $ex + $rep - $baja - $cierre;
			$vm = isset($ventasMov[$it['id']]) ? $ventasMov[$it['id']] : 0;
			$t = isset($teo[$it['id']]) ? $teo[$it['id']] : 0;
			if (!$p && !$t && !$vm) {
				continue; // sin movimiento en el turno
			}
			$deConteo = sabrooskiposEsDeConteo($db, $it['id']);
			if ($deConteo) {
				$u = ($it['unidad'] == 'uds' ? 'uds' : 'g');
				foreach (array($ant, $ini, $rep, $baja, $cierre, $real, $t, $vm) as $i => $v) {
					$conteo[$u][$i] += $v;
				}
				if ($u == 'g') {
					continue;
				}
			}
			$filas[] = array($it['label'], $it['unidad'], $ant / $f, $ini / $f, $rep / $f, ($ex + $rep) / $f, $baja / $f, $cierre / $f, $real / $f, $vm / $f,
				($deConteo ? ($t ? $t / $f : '') : $t / $f), ($deConteo ? '' : ($real - $t) / $f));
		}
		if ($g['titulo'] == 'Mix por sabor' && $mixSinSabor > 0) {
			$filas[] = array('Mix sin sabor registrado (ventas fuera del popup)', 'g', '', '', '', '', '', '', '', '', $mixSinSabor, '');
		}
		// Totales de lo que se controla por conteo: real (todas las variedades) vs teórico
		// (receta + adicionales + lo que ya descuentan los especiales por kit). Topping y sirope
		// a granel ya no van por conteo (bajan al vender): su renglón es el del insumo; el total
		// solo sale en turnos de antes del granel.
		$total = null;
		$hay = function ($c, $teorico) {
			return abs($teorico) > 0.0001 || abs($c[5]) > 0.0001 || abs($c[0] + $c[1] + $c[2]) > 0.0001;
		};
		$fila = function ($label, $u, $c, $teorico) {
			return array($label, $u, $c[0], $c[1], $c[2], $c[0] + $c[1] + $c[2], $c[3], $c[4], $c[5], $c[7], $teorico, $c[5] - $teorico);
		};
		if ($g['titulo'] == 'Siropes' && $hay($conteo['g'], $teoSirope)) {
			$total = $fila('TOTAL SIROPE', 'g', $conteo['g'], $teoSirope + $conteo['g'][6]);
		} elseif ($g['titulo'] == 'Toppings') {
			$filas[] = $fila('TOTAL PIRULÍN Y OREO', 'uds', $conteo['uds'], $teoPiru + $conteo['uds'][6]);
			if ($hay($conteo['g'], $teoTopping)) {
				$total = $fila('TOTAL TOPPING', 'g', $conteo['g'], $teoTopping + $conteo['g'][6]);
			}
		}
		if ($filas || $total) {
			$secciones[] = array('titulo' => $g['titulo'].($g['unidad'] == 'kg' ? ' (kg)' : ''), 'columnas' => $cols, 'filas' => $filas, 'total' => $total);
		}
	}
	return array('titulo' => 'SABROOSKI – INSUMOS DEL MÓVIL', 'hoja' => 'Insumos',
		'nota' => 'Consumo real = existencia anterior + carga inicial + reposición − regalías/dañadas − cierre. Por turnos, la existencia anterior es lo que dejó el turno anterior después de su conteo. Topping y sirope van a granel (en gramos) y bajan al vender según la receta; '
			.'Pirulín y Oreo se comparan en total contra el conteo. '
			.'Sin conteo de cierre, el cierre es lo que dice el sistema.',
		'secciones' => $secciones);
}

/**
 * Planilla "Costos y margen": ventas del turno por producto con el costo de su receta, y
 * el costo por receta de todo el catálogo (para comparar con la estructura de costos).
 *
 * @param array $data
 * @return array
 */
function sabrooskiposReporteCostos($data)
{
	global $db;
	require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos_recetas.lib.php';

	$prods = array();
	foreach ($data['lineas'] as $l) {
		if ($l['fk_product'] <= 0) {
			continue;
		}
		$k = $l['fk_product'];
		if (!isset($prods[$k])) {
			$prods[$k] = array('label' => $l['label'], 'ref' => $l['ref'], 'qty' => 0, 'ttc' => 0, 'ht' => 0);
		}
		$prods[$k]['qty'] += $l['qty'];
		$prods[$k]['ttc'] += $l['total'];
		$prods[$k]['ht'] += $l['total_ht'];
	}
	uasort($prods, function ($a, $b) {
		return strcmp($a['ref'], $b['ref']);
	});
	$filas = array();
	$tot = array('TOTAL', '', 0, 0, '', 0, 0, '');
	foreach ($prods as $pid => $p) {
		$cu = sabrooskiposCostoReceta($db, $pid);
		$costo = $cu['costo'] * $p['qty'];
		$margen = $p['ht'] - $costo;
		$filas[] = array($p['label'], $p['qty'], $p['ttc'], $p['ht'], $cu['costo'], $costo, $margen, ($p['ht'] != 0 ? 100 * $margen / $p['ht'] : ''));
		$tot[2] += $p['ttc'];
		$tot[3] += $p['ht'];
		$tot[5] += $costo;
		$tot[6] += $margen;
	}
	$tot[7] = ($tot[3] != 0 ? 100 * $tot[6] / $tot[3] : '');
	$cols = array(array('Producto', 'text'), array('Uds', 'num'), array('Venta con IVA ($)', 'usd'), array('Venta sin IVA ($)', 'usd'),
		array('Costo unit. ($)', 'usd'), array('Costo total ($)', 'usd'), array('Margen ($)', 'usd'), array('Margen %', 'num'));

	// Costo por receta del catálogo en venta.
	$filas2 = array();
	$resql = $db->query("SELECT p.rowid, p.ref, p.label, p.price_ttc, p.price, p.tosell FROM ".MAIN_DB_PREFIX."product p"
		." WHERE p.entity IN (".getEntity('product').") AND (p.rowid IN (SELECT fk_product_pere FROM ".MAIN_DB_PREFIX."product_association WHERE incdec = 1)"
		." OR p.ref LIKE 'PV-%') ORDER BY p.ref");
	while ($resql && ($o = $db->fetch_object($resql))) {
		$cu = sabrooskiposCostoReceta($db, $o->rowid);
		$det = array();
		foreach ($cu['detalle'] as $k => $v) {
			$det[] = $k.' '.price($v, 0, '', 1, -1, 4);
		}
		$filas2[] = array($o->label.($o->tosell ? '' : ' (fuera de venta)'), (float) $o->price_ttc, (float) $o->price, $cu['costo'],
			((float) $o->price > 0 ? 100 * ((float) $o->price - $cu['costo']) / (float) $o->price : ''), implode(' · ', $det));
	}
	$cols2 = array(array('Producto', 'text'), array('Precio con IVA ($)', 'usd'), array('Precio sin IVA ($)', 'usd'), array('Costo receta ($)', 'usd'),
		array('Margen %', 'num'), array('Detalle', 'text'));

	// Regalías y dañadas: costo (de receta al registrarlas).
	$filas3 = array();
	$tot3 = array('TOTAL', '', '', '', 0);
	foreach ($data['bajas'] as $b) {
		$filas3[] = array($b['label'].($b['sabores_txt'] ? ' ('.$b['sabores_txt'].')' : ''), ($b['tipo'] == 'regalia' ? 'Regalía' : 'Dañada'), $b['qty'], $b['motivo'].' · autorizó '.$b['autoriza'], $b['costo']);
		$tot3[4] += $b['costo'];
	}
	$cols3 = array(array('Producto', 'text'), array('Tipo', 'text'), array('Uds', 'num'), array('Motivo', 'text'), array('Costo ($)', 'usd'));

	return array('titulo' => 'SABROOSKI – COSTOS Y MARGEN', 'hoja' => 'Costos',
		'nota' => 'Costo de receta: insumos fijos × costo + mix, sirope y topping × costo de referencia por gramo + pirulín/oreo. Margen sobre la venta sin IVA.',
		'secciones' => array(
			array('titulo' => 'Ventas del turno', 'columnas' => $cols, 'filas' => $filas, 'total' => ($filas ? $tot : null)),
			array('titulo' => 'Regalías y dañadas', 'columnas' => $cols3, 'filas' => $filas3, 'total' => ($filas3 ? $tot3 : null)),
			array('titulo' => 'Costo por receta', 'columnas' => $cols2, 'filas' => $filas2, 'total' => null),
		));
}
