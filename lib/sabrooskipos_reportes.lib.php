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
 *          datos del sistema. Un turno = fecha + terminal TakePOS.
 *
 * Cada reporte devuelve una estructura neutra que se pinta en HTML o en Excel:
 *   array('titulo' => '', 'secciones' => array(
 *       array('titulo' => '', 'columnas' => array(array('label', 'tipo')), 'filas' => array(...), 'total' => array(...) | null),
 *   ))
 * tipo: 'text', 'int', 'num' (2 decimales), 'usd', 'bs'.
 *
 * Reglas para leer los movimientos de stock del almacén del terminal en el día:
 *  - Existencia (EX)  = stock al inicio del día + ajustes "Existencia inicial…".
 *  - Reposición (REP) = entradas que no son de factura ni "Existencia inicial…" / "Ajuste…".
 *  - Venta directa    = líneas de tickets con pago. Crédito/delivery = tickets sin pagos
 *                       (+ salidas "Crédito…"). Regalías = salidas "Regal…". Dañadas = "Dañad…".
 *  - Cierre           = stock al final del día. La diferencia es el consumo/ajuste no registrado.
 *  - Conos            = "+N conos" en la descripción de la línea (o "N conos" en el movimiento).
 */

require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

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
	$out = array();
	$sql = "SELECT f.datef, f.pos_source, COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."facture f";
	$sql .= " WHERE f.entity = ".((int) $conf->entity)." AND f.module_source = 'takepos' AND f.fk_statut >= 1";
	$sql .= " GROUP BY f.datef, f.pos_source ORDER BY f.datef DESC, f.pos_source LIMIT ".((int) $limit);
	$resql = $db->query($sql);
	while ($resql && ($o = $db->fetch_object($resql))) {
		$name = getDolGlobalString('TAKEPOS_TERMINAL_NAME_'.$o->pos_source, 'Terminal '.$o->pos_source);
		$out[] = array('fecha' => $o->datef, 'terminal' => (string) $o->pos_source,
			'label' => dol_print_date($db->jdate($o->datef), 'day').' · '.$name.' ('.$o->nb.' tickets)');
	}
	return $out;
}

/**
 * Carga todos los datos del turno una sola vez (los cuatro reportes salen de aquí).
 *
 * @param DoliDB $db
 * @param string $fecha     'YYYY-MM-DD'
 * @param string $terminal
 * @return array
 */
function sabrooskiposReporteDatos($db, $fecha, $terminal)
{
	global $conf;
	$entity = (int) $conf->entity;
	$wh = getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$terminal);
	$d0 = $fecha.' 00:00:00';
	$d1 = $fecha.' 23:59:59';

	$data = array('fecha' => $fecha, 'terminal' => $terminal, 'almacen' => $wh,
		'terminal_nombre' => getDolGlobalString('TAKEPOS_TERMINAL_NAME_'.$terminal, 'Terminal '.$terminal),
		'tickets' => array(), 'lineas' => array(), 'pagos' => array(), 'productos' => array(), 'tasa' => 0,
		'hora_inicio' => '', 'hora_cierre' => '', 'usuarios' => array());

	// Tickets del turno, con lo pagado.
	$sql = "SELECT f.rowid, f.ref, f.datec, f.total_ttc, f.note_private, s.nom AS cliente, u.firstname, u.lastname, u.login,";
	$sql .= " (SELECT COALESCE(SUM(pf.amount), 0) FROM ".MAIN_DB_PREFIX."paiement_facture pf WHERE pf.fk_facture = f.rowid) AS pagado";
	$sql .= " FROM ".MAIN_DB_PREFIX."facture f LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = f.fk_soc";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = f.fk_user_valid";
	$sql .= " WHERE f.entity = ".$entity." AND f.module_source = 'takepos' AND f.fk_statut >= 1";
	$sql .= " AND f.pos_source = '".$db->escape($terminal)."' AND f.datef = '".$db->escape($fecha)."'";
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

	if ($ids) {
		// Líneas de venta.
		$sql = "SELECT d.fk_facture, d.fk_product, d.description, d.qty, d.remise_percent, d.total_ttc, p.ref, p.label";
		$sql .= " FROM ".MAIN_DB_PREFIX."facturedet d LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = d.fk_product";
		$sql .= " WHERE d.fk_facture IN (".implode(',', $ids).") ORDER BY d.fk_facture, d.rang, d.rowid";
		$resql = $db->query($sql);
		while ($resql && ($o = $db->fetch_object($resql))) {
			$data['lineas'][] = array('fk_facture' => (int) $o->fk_facture, 'fk_product' => (int) $o->fk_product, 'ref' => (string) $o->ref,
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
		$sql = "SELECT p.rowid, p.ref, p.label, p.price_ttc, p.tosell,";
		$sql .= " COALESCE(SUM(CASE WHEN m.datem < '".$d0."' THEN m.value ELSE 0 END), 0) AS s0,";
		$sql .= " COALESCE(SUM(CASE WHEN m.datem <= '".$d1."' THEN m.value ELSE 0 END), 0) AS s1,";
		$sql .= " COALESCE(SUM(CASE WHEN m.datem BETWEEN '".$d0."' AND '".$d1."' AND m.origintype <> 'facture' AND m.label LIKE 'Existencia inicial%' THEN m.value ELSE 0 END), 0) AS inicial,";
		$sql .= " COALESCE(SUM(CASE WHEN m.datem BETWEEN '".$d0."' AND '".$d1."' AND COALESCE(m.origintype, '') <> 'facture' AND m.value > 0 AND m.label NOT LIKE 'Existencia inicial%' AND m.label NOT LIKE 'Ajuste%' THEN m.value ELSE 0 END), 0) AS rep,";
		$sql .= " COALESCE(SUM(CASE WHEN m.datem BETWEEN '".$d0."' AND '".$d1."' AND COALESCE(m.origintype, '') <> 'facture' AND m.value < 0 AND m.label LIKE 'Regal%' THEN -m.value ELSE 0 END), 0) AS regalo,";
		$sql .= " COALESCE(SUM(CASE WHEN m.datem BETWEEN '".$d0."' AND '".$d1."' AND COALESCE(m.origintype, '') <> 'facture' AND m.value < 0 AND m.label LIKE 'Dañad%' THEN -m.value ELSE 0 END), 0) AS danadas,";
		$sql .= " COALESCE(SUM(CASE WHEN m.datem BETWEEN '".$d0."' AND '".$d1."' AND COALESCE(m.origintype, '') <> 'facture' AND m.value < 0 AND m.label LIKE 'Crédito%' THEN -m.value ELSE 0 END), 0) AS credito_mov,";
		$sql .= " GROUP_CONCAT(CASE WHEN m.datem BETWEEN '".$d0."' AND '".$d1."' AND m.label LIKE 'Regal%' THEN m.label END SEPARATOR ' | ') AS regalo_labels";
		$sql .= " FROM ".MAIN_DB_PREFIX."product p JOIN ".MAIN_DB_PREFIX."stock_mouvement m ON m.fk_product = p.rowid AND m.fk_entrepot = ".((int) $wh);
		$sql .= " WHERE p.entity = ".$entity;
		$sql .= " GROUP BY p.rowid, p.ref, p.label, p.price_ttc, p.tosell";
		$resql = $db->query($sql);
		while ($resql && ($o = $db->fetch_object($resql))) {
			$data['productos'][(int) $o->rowid] = array('ref' => $o->ref, 'label' => $o->label, 'precio' => (float) $o->price_ttc, 'tosell' => (int) $o->tosell,
				'ex' => (float) $o->s0 + (float) $o->inicial, 'rep' => (float) $o->rep, 'regalo' => (float) $o->regalo, 'danadas' => (float) $o->danadas,
				'credito_mov' => (float) $o->credito_mov, 'cierre' => (float) $o->s1, 'regalo_conos' => sabrooskiposConos((string) $o->regalo_labels, true),
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
	$orden = array('Servido en camión', 'Especial domo', 'Bandejas 500 g', 'Sundae congelado', 'Empaquetado', 'Golosinas', 'Adicional', 'Topping', 'Sirope', 'Insumos');
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
	return array(
		array('Fecha', dol_print_date($ts, '%d/%m/%Y')),
		array('Día', $dias[(int) dol_print_date($ts, '%w')]),
		array('Turno', $data['terminal_nombre'].' (terminal '.$data['terminal'].')'),
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
	foreach ($data['productos'] as $p) {
		if ($p['regalo'] > 0) {
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
	$rows = array();
	foreach ($data['productos'] as $p) {
		$cat = $p['categoria'];
		$key = ($cat == 'Bandejas 500 g' ? 'Bandeja' : ($cat == 'Sundae congelado' ? 'Sundae congelado' : $p['ref']));
		if (!isset($rows[$key])) {
			$rows[$key] = array('o' => sabrooskiposOrdenCategoria($cat), 'ref' => $p['ref'], 'label' => ($key == $p['ref'] ? $p['label'] : $key),
				'venta' => ($p['tosell'] && !in_array($cat, array('Topping', 'Sirope', 'Insumos'))), 'precio' => $p['precio'],
				'ex' => 0, 'rep' => 0, 'vd' => 0, 'vd_total' => 0, 'regalo' => 0, 'credito' => 0, 'danadas' => 0, 'cierre' => 0,
				'sin_stock' => !empty($p['sin_stock']));
		}
		$r = &$rows[$key];
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
	uasort($rows, function ($a, $b) {
		return ($a['o'] - $b['o']) ?: strcmp($a['ref'], $b['ref']);
	});
	$filas = array();
	$tventas = 0;
	foreach ($rows as $r) {
		$total = $r['ex'] + $r['rep'];
		$auto = $r['regalo'] + $r['credito'] + $r['danadas'];
		$segun = $total - $r['cierre'];
		$registradas = $r['vd'] + $auto;
		$pu = ($r['vd'] ? $r['vd_total'] / $r['vd'] : ($r['venta'] ? $r['precio'] : ''));
		if ($r['sin_stock']) {
			// Hecho al momento (especiales, adicionales): solo la venta, sin control de stock.
			$filas[] = array($r['label'].' (sin stock propio)', '', '', '', $r['vd'], $pu, $r['vd_total'], '', $r['credito'] ?: '', '', '', '', '', '', '');
		} else {
			$filas[] = array($r['label'], $r['ex'], $r['rep'], $total, $r['vd'], $pu,
				$r['vd_total'], $r['regalo'], $r['credito'], $r['danadas'], $auto, $r['cierre'], $segun, $registradas, $segun - $registradas);
		}
		$tventas += $r['vd_total'];
	}
	$cols = array(array('Descripción', 'text'), array('Existencia', 'num'), array('Reposición', 'num'), array('Total', 'num'), array('Ventas uds', 'num'),
		array('P. Unit ($)', 'usd'), array('Total ventas ($)', 'usd'), array('Regalías', 'num'), array('Crédito', 'num'), array('Dañadas', 'num'),
		array('Total autoconsumo', 'num'), array('Existencia al cierre', 'num'), array('Salidas según cierre', 'num'), array('Salidas registradas', 'num'), array('Diferencia', 'num'));
	return array('titulo' => 'SABROOSKI – INVENTARIO DE VENTAS', 'hoja' => 'Inventario Ventas',
		'nota' => 'Toppings y siropes en gramos. En toppings, siropes e insumos la diferencia es el consumo del turno.',
		'secciones' => array(array('titulo' => 'Almacén del terminal', 'columnas' => $cols, 'filas' => $filas,
			'total' => array('TOTAL VENTAS ($)', '', '', '', '', '', $tventas, '', '', '', '', '', '', '', ''))));
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
	return price($v, 0, '', 1, -1, 2);
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
	$fmt = array('int' => '#,##0', 'num' => '#,##0.00', 'usd' => '#,##0.00', 'bs' => '#,##0.00');
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
