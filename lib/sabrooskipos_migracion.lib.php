<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/lib/sabrooskipos_migracion.lib.php
 * \ingroup sabrooskipos
 * \brief   TEMPORAL: paquete de migración completo local -> producción.
 *          Catálogo (sabrooskiposExportCatalogo/ImportCatalogo) + configuración del
 *          POS y del popup + turnos (tickets, pagos, movimientos bancarios y de stock).
 *          Todo se resuelve por ref/label/código, nunca por ID.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/multicurrency/class/multicurrency.class.php';
require_once __DIR__.'/sabrooskipos.lib.php';

/**
 * Genera el paquete completo, o solo catálogo + configuración si $conTurnos es false.
 *
 * @param DoliDB $db
 * @param string $datefrom   'YYYY-MM-DD'
 * @param string $dateto     'YYYY-MM-DD'
 * @param bool   $conTurnos  false = sin tickets/pagos/stock (solo productos, categorías y config)
 * @return array
 */
function sabrooskiposExportPaquete($db, $datefrom, $dateto, $conTurnos = true)
{
	return array(
		'tipo' => 'sabrooskipos-paquete',
		'version' => 1,
		'generated_at' => dol_print_date(dol_now(), 'dayhourrfc'),
		'catalogo' => sabrooskiposExportCatalogo($db),
		'config' => sabrooskiposExportConfig($db),
		'turnos' => ($conTurnos ? sabrooskiposExportTurnos($db, $datefrom, $dateto) : array()),
	);
}

/**
 * Importa el paquete completo. El llamador maneja la transacción (commit o, para
 * simular, rollback).
 *
 * @param DoliDB $db
 * @param User   $user
 * @param array  $data
 * @param array  $report  ['errores'=>[], 'notas'=>[], 'tickets'=>[], ...]
 * @return int  1 OK, 0 error
 */
function sabrooskiposImportPaquete($db, $user, $data, &$report)
{
	if (!is_array($data) || (isset($data['tipo']) ? $data['tipo'] : '') !== 'sabrooskipos-paquete') {
		$report['errores'][] = 'El archivo no es un paquete de migración de SabrooskiPOS.';
		return 0;
	}
	$catreport = array('categorias_creadas' => array(), 'productos_creados' => array(), 'productos_existentes' => array(), 'errores' => array(), 'notas' => array());
	if (!empty($data['catalogo'])) {
		sabrooskiposImportCatalogo($db, $user, $data['catalogo'], $catreport);
	}
	$report['catalogo'] = $catreport;
	foreach ($catreport['errores'] as $e) {
		$report['errores'][] = 'Catálogo: '.$e;
	}
	if (!empty($data['config'])) {
		sabrooskiposImportConfig($db, $user, $data['config'], $report);
	}
	if (!empty($data['turnos'])) {
		foreach ($data['turnos'] as $turno) {
			sabrooskiposImportTurno($db, $user, $turno, $report);
		}
	}
	return count($report['errores']) ? 0 : 1;
}

/**
 * Cuenta registros clave. Sirve para comprobar que una simulación (rollback) no
 * dejó nada guardado: algunas sentencias (DDL) hacen commit implícito en MySQL.
 *
 * @param DoliDB $db
 * @return array<string,int>
 */
function sabrooskiposSnapshot($db)
{
	$out = array();
	foreach (array('product', 'categorie', 'facture', 'paiement', 'bank', 'stock_mouvement', 'societe', 'const', 'c_paiement', 'bank_account', 'entrepot') as $t) {
		$resql = $db->query("SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX.$t);
		$out[$t] = ($resql ? (int) $db->fetch_object($resql)->nb : -1);
	}
	return $out;
}

/* ====================================================================== *
 * Configuración
 * ====================================================================== */

/**
 * Configuración del POS y del popup, por ref/label/código.
 *
 * @param DoliDB $db
 * @return array
 */
function sabrooskiposExportConfig($db)
{
	global $conf;
	$entity = (int) $conf->entity;

	// Monedas.
	$currencies = array();
	$resql = $db->query("SELECT m.code, m.name, (SELECT r.rate FROM ".MAIN_DB_PREFIX."multicurrency_rate r WHERE r.fk_multicurrency = m.rowid ORDER BY r.date_sync DESC, r.rowid DESC LIMIT 1) AS rate FROM ".MAIN_DB_PREFIX."multicurrency m WHERE m.entity = ".$entity);
	while ($resql && ($o = $db->fetch_object($resql))) {
		$currencies[] = array('code' => $o->code, 'name' => $o->name, 'rate' => (float) $o->rate);
	}

	// Cuentas bancarias y almacenes.
	$accounts = array();
	$accref = array();
	$resql = $db->query("SELECT rowid, ref, label, courant, currency_code FROM ".MAIN_DB_PREFIX."bank_account WHERE entity = ".$entity." AND clos = 0");
	while ($resql && ($o = $db->fetch_object($resql))) {
		$accounts[] = array('ref' => $o->ref, 'label' => $o->label, 'courant' => (int) $o->courant, 'currency_code' => (string) $o->currency_code);
		$accref[(int) $o->rowid] = $o->ref;
	}
	$warehouses = array();
	$whref = array();
	$resql = $db->query("SELECT rowid, ref, lieu FROM ".MAIN_DB_PREFIX."entrepot WHERE entity = ".$entity);
	while ($resql && ($o = $db->fetch_object($resql))) {
		$warehouses[] = array('ref' => $o->ref, 'lieu' => (string) $o->lieu);
		$whref[(int) $o->rowid] = $o->ref;
	}

	// Formas de pago activas.
	$paymodes = array();
	$resql = $db->query("SELECT code, libelle, type FROM ".MAIN_DB_PREFIX."c_paiement WHERE active = 1 AND entity IN (".getEntity('c_paiement').")");
	while ($resql && ($o = $db->fetch_object($resql))) {
		$paymodes[] = array('code' => $o->code, 'libelle' => $o->libelle, 'type' => (int) $o->type);
	}

	// Constantes: por cuenta (ref), almacén (ref), categoría (label), producto (ref) o valor.
	$catlabel = array();
	$resql = $db->query("SELECT rowid, label FROM ".MAIN_DB_PREFIX."categorie WHERE type = 0 AND entity = ".$entity);
	while ($resql && ($o = $db->fetch_object($resql))) {
		$catlabel[(int) $o->rowid] = $o->label;
	}
	$constants = array();
	$resql = $db->query("SELECT name, value FROM ".MAIN_DB_PREFIX."const WHERE entity = ".$entity." AND (name LIKE 'CASHDESK\\_%' OR name LIKE 'TAKEPOS\\_%' OR name LIKE 'SABROOSKIPOS\\_%')");
	while ($resql && ($o = $db->fetch_object($resql))) {
		$n = $o->name;
		$v = (string) $o->value;
		if (preg_match('/^CASHDESK_ID_BANKACCOUNT_/', $n)) {
			if (isset($accref[(int) $v])) {
				$constants[] = array('name' => $n, 'kind' => 'account', 'value' => $accref[(int) $v]);
			}
		} elseif (preg_match('/^(CASHDESK_ID_WAREHOUSE|SABROOSKIPOS_ID_WAREHOUSE_MAIN)/', $n)) {
			if (isset($whref[(int) $v])) {
				$constants[] = array('name' => $n, 'kind' => 'warehouse', 'value' => $whref[(int) $v]);
			}
		} elseif (preg_match('/^CASHDESK_ID_THIRDPARTY/', $n)) {
			continue; // cada instalación conserva su cliente genérico
		} elseif (preg_match('/^(SABROOSKIPOS_CATEGORY_|TAKEPOS_ROOT_CATEGORY_ID)/', $n)) {
			if (isset($catlabel[(int) $v])) {
				$constants[] = array('name' => $n, 'kind' => 'category', 'value' => $catlabel[(int) $v]);
			}
		} elseif (preg_match('/^SABROOSKIPOS_HIDDEN_CATEGORIES/', $n)) {
			$labels = array();
			foreach (explode(',', $v) as $cid) {
				if (isset($catlabel[(int) trim($cid)])) {
					$labels[] = $catlabel[(int) trim($cid)];
				}
			}
			$constants[] = array('name' => $n, 'kind' => 'categories', 'value' => $labels);
		} elseif (preg_match('/^SABROOSKIPOS_PRODUCT_/', $n)) {
			$p = new Product($db);
			if ((int) $v > 0 && $p->fetch((int) $v) > 0) {
				$constants[] = array('name' => $n, 'kind' => 'product', 'value' => $p->ref);
			}
		} elseif (preg_match('/^(TAKEPOS_DELAYED_PAYMENT|TAKEPOS_NUM_TERMINALS|TAKEPOS_TERMINAL_NAME_\d+|TAKEPOS_SORTPRODUCTFIELD|SABROOSKIPOS_TVA_TX|CASHDESK_NO_DECREASE_STOCK\d*)$/', $n)) {
			$constants[] = array('name' => $n, 'kind' => 'plain', 'value' => $v);
		}
	}

	return array('currencies' => $currencies, 'bank_accounts' => $accounts, 'warehouses' => $warehouses,
		'payment_modes' => $paymodes, 'constants' => $constants);
}

/**
 * Aplica la configuración. Crea monedas, cuentas, almacenes y formas de pago que falten.
 *
 * @param DoliDB $db
 * @param User   $user
 * @param array  $cfg
 * @param array  $report
 * @return void
 */
function sabrooskiposImportConfig($db, $user, $cfg, &$report)
{
	global $conf, $mysoc;
	$entity = (int) $conf->entity;

	foreach ((array) $cfg['currencies'] as $c) {
		$mc = new MultiCurrency($db);
		if ($mc->fetch(0, $c['code']) <= 0) {
			$mc = new MultiCurrency($db);
			$mc->code = $c['code'];
			$mc->name = $c['name'];
			if ($mc->create($user) > 0) {
				$mc->addRate($c['rate'] > 0 ? $c['rate'] : 1);
				$report['notas'][] = 'Moneda creada: '.$c['code'];
			} else {
				$report['errores'][] = 'Moneda '.$c['code'].': '.$mc->error;
			}
		}
	}

	foreach ((array) $cfg['bank_accounts'] as $a) {
		$acc = new Account($db);
		if ($acc->fetch(0, $a['ref']) > 0) {
			if ((int) $acc->courant != (int) $a['courant']) {
				$db->query("UPDATE ".MAIN_DB_PREFIX."bank_account SET courant = ".((int) $a['courant'])." WHERE rowid = ".((int) $acc->id));
				$report['notas'][] = 'Cuenta '.$a['ref'].': tipo '.$a['courant'];
			}
			if ((string) $acc->currency_code !== (string) $a['currency_code']) {
				$report['errores'][] = 'Cuenta '.$a['ref'].' es '.$acc->currency_code.' en destino y '.$a['currency_code'].' en origen: corregir a mano.';
			}
			continue;
		}
		$acc = new Account($db);
		$acc->ref = $a['ref'];
		$acc->label = $a['label'];
		$acc->type = (int) $a['courant'];
		$acc->courant = (int) $a['courant'];
		$acc->currency_code = ($a['currency_code'] ? $a['currency_code'] : $conf->currency);
		$acc->country_id = $mysoc->country_id;
		$acc->date_solde = dol_now();
		$acc->solde = 0;
		if ($acc->create($user) > 0) {
			$report['notas'][] = 'Cuenta bancaria creada: '.$a['ref'].' ('.$a['label'].')';
		} else {
			$report['errores'][] = 'Cuenta '.$a['ref'].': '.$acc->error;
		}
	}

	foreach ((array) $cfg['warehouses'] as $w) {
		$e = new Entrepot($db);
		if ($e->fetch(0, $w['ref']) > 0) {
			continue;
		}
		$e->ref = $w['ref'];
		$e->label = $w['ref'];
		$e->lieu = $w['lieu'];
		$e->statut = 1;
		if ($e->create($user) > 0) {
			$report['notas'][] = 'Almacén creado: '.$w['ref'];
		} else {
			$report['errores'][] = 'Almacén '.$w['ref'].': '.$e->error;
		}
	}

	foreach ((array) $cfg['payment_modes'] as $pm) {
		$resql = $db->query("SELECT id, active FROM ".MAIN_DB_PREFIX."c_paiement WHERE code = '".$db->escape($pm['code'])."' AND entity IN (".getEntity('c_paiement').")");
		$o = $resql ? $db->fetch_object($resql) : null;
		if (!$o) {
			$db->query("INSERT INTO ".MAIN_DB_PREFIX."c_paiement (entity, code, libelle, type, active, position) VALUES (".$entity.", '".$db->escape($pm['code'])."', '".$db->escape($pm['libelle'])."', ".((int) $pm['type']).", 1, 0)");
			$report['notas'][] = 'Forma de pago creada: '.$pm['code'].' ('.$pm['libelle'].')';
		} elseif (!$o->active) {
			$db->query("UPDATE ".MAIN_DB_PREFIX."c_paiement SET active = 1 WHERE id = ".((int) $o->id));
			$report['notas'][] = 'Forma de pago activada: '.$pm['code'];
		}
	}

	foreach ((array) $cfg['constants'] as $c) {
		$val = null;
		if ($c['kind'] == 'plain') {
			$val = $c['value'];
		} elseif ($c['kind'] == 'account') {
			$acc = new Account($db);
			$val = ($acc->fetch(0, $c['value']) > 0 ? $acc->id : null);
		} elseif ($c['kind'] == 'warehouse') {
			$e = new Entrepot($db);
			$val = ($e->fetch(0, $c['value']) > 0 ? $e->id : null);
		} elseif ($c['kind'] == 'category') {
			$cat = new Categorie($db);
			$val = ($cat->fetch(0, $c['value'], Categorie::TYPE_PRODUCT) > 0 ? $cat->id : null);
		} elseif ($c['kind'] == 'categories') {
			$ids = array();
			foreach ((array) $c['value'] as $label) {
				$cat = new Categorie($db);
				if ($cat->fetch(0, $label, Categorie::TYPE_PRODUCT) > 0) {
					$ids[] = $cat->id;
				}
			}
			$val = implode(',', $ids);
		} elseif ($c['kind'] == 'product') {
			$p = new Product($db);
			$val = ($p->fetch(0, $c['value']) > 0 ? $p->id : null);
		}
		if ($val === null) {
			$report['errores'][] = 'Constante '.$c['name'].': no se encontró "'.(is_array($c['value']) ? implode(',', $c['value']) : $c['value']).'" en destino.';
			continue;
		}
		dolibarr_set_const($db, $c['name'], (string) $val, 'chaine', 0, '', $entity);
		$report['config'][] = $c['name'].' = '.$val.($c['kind'] != 'plain' ? ' ('.(is_array($c['value']) ? implode(', ', $c['value']) : $c['value']).')' : '');
	}
}

/* ====================================================================== *
 * Turnos
 * ====================================================================== */

/**
 * Exporta los tickets TakePOS (con líneas, pagos y movimientos bancarios) y los
 * movimientos de stock manuales del rango, agrupados por día y terminal.
 *
 * @param DoliDB $db
 * @param string $datefrom
 * @param string $dateto
 * @return array
 */
function sabrooskiposExportTurnos($db, $datefrom, $dateto)
{
	global $conf;
	$entity = (int) $conf->entity;
	$turnos = array();

	$sql = "SELECT f.rowid, f.ref, f.datef, f.pos_source, f.datec, f.date_valid, f.note_private, f.note_public,";
	$sql .= " f.multicurrency_code, f.multicurrency_tx, f.total_ttc, f.fk_soc, s.nom AS socname";
	$sql .= " FROM ".MAIN_DB_PREFIX."facture f LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = f.fk_soc";
	$sql .= " WHERE f.entity = ".$entity." AND f.module_source = 'takepos' AND f.fk_statut >= 1";
	$sql .= " AND f.datef BETWEEN '".$db->escape($datefrom)."' AND '".$db->escape($dateto)."'";
	$sql .= " ORDER BY f.datef, f.pos_source, f.datec, f.rowid";
	$resql = $db->query($sql);
	$invoices = array();
	while ($resql && ($o = $db->fetch_object($resql))) {
		$invoices[] = $o;
	}

	foreach ($invoices as $o) {
		$key = $o->datef.'|'.$o->pos_source;
		if (!isset($turnos[$key])) {
			$turnos[$key] = array('fecha' => $o->datef, 'terminal' => (string) $o->pos_source, 'tickets' => array(), 'stock' => array(), 'tasas' => array());
		}
		$generic = (getDolGlobalInt('CASHDESK_ID_THIRDPARTY'.$o->pos_source) == (int) $o->fk_soc);
		$tk = array(
			'ref_origen' => $o->ref,
			'datec' => $o->datec, // date_valid es solo fecha: la hora del ticket está en datec
			'cliente' => $generic ? '' : html_entity_decode((string) $o->socname, ENT_QUOTES, 'UTF-8'),
			'note_private' => (string) $o->note_private,
			'note_public' => (string) $o->note_public,
			'multicurrency_code' => (string) $o->multicurrency_code,
			'multicurrency_tx' => (float) $o->multicurrency_tx,
			'total_ttc' => (float) $o->total_ttc,
			'lineas' => array(),
			'pagos' => array(),
		);
		$r2 = $db->query("SELECT d.fk_product, p.ref, d.description, d.qty, d.subprice, d.tva_tx, d.remise_percent, d.total_ttc FROM ".MAIN_DB_PREFIX."facturedet d LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = d.fk_product WHERE d.fk_facture = ".((int) $o->rowid)." ORDER BY d.rang, d.rowid");
		while ($r2 && ($l = $db->fetch_object($r2))) {
			$tk['lineas'][] = array('ref' => (string) $l->ref, 'desc' => (string) $l->description, 'qty' => (float) $l->qty,
				'pu_ttc' => round((float) $l->subprice * (1 + (float) $l->tva_tx / 100), 8), 'tva_tx' => (float) $l->tva_tx,
				'remise' => (float) $l->remise_percent, 'total_ttc' => (float) $l->total_ttc);
		}
		$sql3 = "SELECT p.datep, p.num_paiement, p.note, pf.amount, pf.multicurrency_amount, cp.code, ba.ref AS account_ref, b.datec AS bank_datec";
		$sql3 .= " FROM ".MAIN_DB_PREFIX."paiement_facture pf JOIN ".MAIN_DB_PREFIX."paiement p ON p.rowid = pf.fk_paiement";
		$sql3 .= " LEFT JOIN ".MAIN_DB_PREFIX."c_paiement cp ON cp.id = p.fk_paiement";
		$sql3 .= " LEFT JOIN ".MAIN_DB_PREFIX."bank b ON b.rowid = p.fk_bank LEFT JOIN ".MAIN_DB_PREFIX."bank_account ba ON ba.rowid = b.fk_account";
		$sql3 .= " WHERE pf.fk_facture = ".((int) $o->rowid)." ORDER BY p.datep, p.rowid";
		$r3 = $db->query($sql3);
		while ($r3 && ($pm = $db->fetch_object($r3))) {
			$tk['pagos'][] = array('datep' => $pm->datep, 'code' => $pm->code, 'cuenta' => (string) $pm->account_ref,
				'monto' => (float) $pm->amount, 'monto_moneda' => (float) $pm->multicurrency_amount,
				'num' => (string) $pm->num_paiement, 'nota' => (string) $pm->note, 'banco_datec' => $pm->bank_datec);
		}
		$turnos[$key]['tickets'][] = $tk;
	}

	// Movimientos de stock que no vienen de una factura, en el almacén de cada terminal y día.
	foreach ($turnos as $key => $t) {
		$whid = getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$t['terminal']);
		$sql = "SELECT m.datem, m.value, m.label, m.inventorycode, p.ref, e.ref AS wref";
		$sql .= " FROM ".MAIN_DB_PREFIX."stock_mouvement m JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = m.fk_product";
		$sql .= " JOIN ".MAIN_DB_PREFIX."entrepot e ON e.rowid = m.fk_entrepot";
		$sql .= " WHERE m.fk_entrepot = ".((int) $whid)." AND (m.origintype IS NULL OR m.origintype = '' OR m.origintype <> 'facture')";
		$sql .= " AND m.datem BETWEEN '".$db->escape($t['fecha'])." 00:00:00' AND '".$db->escape($t['fecha'])." 23:59:59'";
		$sql .= " ORDER BY m.datem, m.rowid";
		$resql = $db->query($sql);
		while ($resql && ($m = $db->fetch_object($resql))) {
			$turnos[$key]['stock'][] = array('datem' => $m->datem, 'ref' => $m->ref, 'almacen' => $m->wref, 'cantidad' => (float) $m->value,
				'label' => (string) $m->label, 'inventorycode' => (string) $m->inventorycode);
		}
		$resql = $db->query("SELECT m.code, r.rate, r.date_sync FROM ".MAIN_DB_PREFIX."multicurrency_rate r JOIN ".MAIN_DB_PREFIX."multicurrency m ON m.rowid = r.fk_multicurrency WHERE DATE(r.date_sync) = '".$db->escape($t['fecha'])."'");
		while ($resql && ($r = $db->fetch_object($resql))) {
			$turnos[$key]['tasas'][] = array('code' => $r->code, 'rate' => (float) $r->rate, 'date_sync' => $r->date_sync);
		}
	}

	return array_values($turnos);
}

/**
 * Reproduce un turno: stock, tickets validados (con descuento de stock del almacén del
 * terminal, como posplus), pagos y movimientos bancarios, todo con la fecha/hora original.
 * No duplica: si el terminal ya tiene tickets ese día, el turno se salta.
 *
 * @param DoliDB $db
 * @param User   $user
 * @param array  $t
 * @param array  $report
 * @return void
 */
function sabrooskiposImportTurno($db, $user, $t, &$report)
{
	global $conf;
	$entity = (int) $conf->entity;
	$term = (string) $t['terminal'];
	$label = 'Turno '.$t['fecha'].' terminal '.$term;

	$resql = $db->query("SELECT COUNT(*) nb FROM ".MAIN_DB_PREFIX."facture WHERE entity = ".$entity." AND module_source = 'takepos' AND pos_source = '".$db->escape($term)."' AND datef = '".$db->escape($t['fecha'])."' AND fk_statut >= 1");
	if ((int) $db->fetch_object($resql)->nb > 0) {
		$report['errores'][] = $label.': ya tiene tickets en destino; no se importa de nuevo.';
		return;
	}
	$wh = getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$term);
	$socgeneric = getDolGlobalInt('CASHDESK_ID_THIRDPARTY'.$term);
	if ($wh <= 0 || $socgeneric <= 0) {
		$report['errores'][] = $label.': falta CASHDESK_ID_WAREHOUSE'.$term.' o CASHDESK_ID_THIRDPARTY'.$term.' en destino (configurar el terminal en TakePOS).';
		return;
	}

	// Verificar referencias antes de escribir.
	$prods = array();
	$missing = array();
	$refs = array();
	foreach ($t['tickets'] as $tk) {
		foreach ($tk['lineas'] as $l) {
			if ($l['ref'] !== '') {
				$refs[$l['ref']] = 1;
			}
		}
	}
	foreach ($t['stock'] as $m) {
		$refs[$m['ref']] = 1;
	}
	foreach (array_keys($refs) as $ref) {
		$p = new Product($db);
		if ($p->fetch(0, $ref) > 0) {
			$prods[$ref] = $p;
		} else {
			$missing[] = $ref;
		}
	}
	if ($missing) {
		$report['errores'][] = $label.': productos inexistentes en destino: '.implode(', ', $missing);
		return;
	}

	foreach ((array) $t['tasas'] as $r) {
		$db->query("INSERT INTO ".MAIN_DB_PREFIX."multicurrency_rate (date_sync, rate, rate_indirect, fk_multicurrency, entity) SELECT '".$db->escape($r['date_sync'])."', ".((float) $r['rate']).", 0, rowid, ".$entity." FROM ".MAIN_DB_PREFIX."multicurrency WHERE code = '".$db->escape($r['code'])."' AND entity = ".$entity);
	}

	$savpdf = getDolGlobalString('MAIN_DISABLE_PDF_AUTOUPDATE');
	$conf->global->MAIN_DISABLE_PDF_AUTOUPDATE = 1;
	$nbstock = 0;
	$moves = $t['stock'];
	$tickets = $t['tickets'];
	$im = 0;
	$total = 0;

	// Recorremos stock y tickets en orden cronológico.
	while (true) {
		$nextm = ($im < count($moves) ? $moves[$im]['datem'] : null);
		$nextt = (count($tickets) ? $tickets[0]['datec'] : null);
		if ($nextm === null && $nextt === null) {
			break;
		}
		if ($nextm !== null && ($nextt === null || $nextm <= $nextt)) {
			$m = $moves[$im++];
			$p = $prods[$m['ref']];
			$e = new Entrepot($db);
			$whm = ($e->fetch(0, $m['almacen']) > 0 ? $e->id : $wh);
			$r = $p->correct_stock($user, $whm, abs($m['cantidad']), $m['cantidad'] < 0 ? 1 : 0, $m['label'], 0, $m['inventorycode']);
			if ($r <= 0) {
				$report['errores'][] = $label.': stock '.$m['ref'].': '.$p->error;
				break;
			}
			$db->query("UPDATE ".MAIN_DB_PREFIX."stock_mouvement SET datem = '".$db->escape($m['datem'])."' WHERE rowid = (SELECT x.id FROM (SELECT MAX(rowid) AS id FROM ".MAIN_DB_PREFIX."stock_mouvement WHERE fk_product = ".((int) $p->id).") x)");
			$nbstock++;
			continue;
		}

		$tk = array_shift($tickets);
		$ts = "'".$db->escape($tk['datec'])."'";
		$inv = new Facture($db);
		$socid = $socgeneric;
		if ($tk['cliente'] !== '') {
			$soc = new Societe($db);
			if ($soc->fetch(0, $tk['cliente']) <= 0) {
				$soc = new Societe($db);
				$soc->name = $tk['cliente'];
				$soc->client = 1;
				$soc->code_client = -1;
				$soc->country_id = $GLOBALS['mysoc']->country_id;
				if ($soc->create($user) <= 0) {
					$report['errores'][] = $label.': cliente '.$tk['cliente'].': '.$soc->error;
					break;
				}
				$report['notas'][] = 'Cliente creado: '.$tk['cliente'];
			}
			$socid = $soc->id;
		}
		$inv->socid = $socid;
		$inv->date = $db->jdate(substr($tk['datec'], 0, 10).' 00:00:00');
		$inv->module_source = 'takepos';
		$inv->pos_source = $term;
		$inv->note_private = $tk['note_private'];
		$inv->note_public = $tk['note_public'];
		$inv->multicurrency_code = $tk['multicurrency_code'];
		$inv->multicurrency_tx = $tk['multicurrency_tx'];
		if ($inv->create($user) <= 0) {
			$report['errores'][] = $label.': factura '.$tk['ref_origen'].': '.$inv->error;
			break;
		}
		$ok = true;
		foreach ($tk['lineas'] as $l) {
			$p = ($l['ref'] !== '' ? $prods[$l['ref']] : null);
			$r = $inv->addline($l['desc'], 0, $l['qty'], $l['tva_tx'], 0, 0, $p ? $p->id : 0, $l['remise'], '', '', 0, 0, 0, 'TTC', $l['pu_ttc'], $p ? $p->type : 0);
			if ($r <= 0) {
				$report['errores'][] = $label.': línea '.$l['ref'].' de '.$tk['ref_origen'].': '.$inv->error;
				$ok = false;
				break;
			}
		}
		if (!$ok) {
			break;
		}
		$inv->fetch($inv->id);
		$sav = getDolGlobalString('STOCK_CALCULATE_ON_BILL');
		$conf->global->STOCK_CALCULATE_ON_BILL = 1;
		$r = $inv->validate($user, '', (getDolGlobalString('CASHDESK_NO_DECREASE_STOCK'.$term) == '1' ? 0 : $wh));
		$conf->global->STOCK_CALCULATE_ON_BILL = $sav;
		if ($r < 0) {
			$report['errores'][] = $label.': validar '.$tk['ref_origen'].': '.$inv->error.' '.implode(', ', (array) $inv->errors);
			break;
		}
		$inv->fetch($inv->id);
		if (abs((float) $inv->total_ttc - (float) $tk['total_ttc']) > 0.005) {
			$report['errores'][] = $label.': '.$tk['ref_origen'].' da '.$inv->total_ttc.' en destino y '.$tk['total_ttc'].' en origen.';
			break;
		}

		foreach ($tk['pagos'] as $pg) {
			$acc = new Account($db);
			if ($pg['cuenta'] === '' || $acc->fetch(0, $pg['cuenta']) <= 0) {
				$report['errores'][] = $label.': cuenta "'.$pg['cuenta'].'" inexistente en destino.';
				$ok = false;
				break;
			}
			$resql = $db->query("SELECT id FROM ".MAIN_DB_PREFIX."c_paiement WHERE code = '".$db->escape($pg['code'])."' AND entity IN (".getEntity('c_paiement').")");
			$o = $resql ? $db->fetch_object($resql) : null;
			if (!$o) {
				$report['errores'][] = $label.': forma de pago '.$pg['code'].' inexistente en destino.';
				$ok = false;
				break;
			}
			$payment = new Paiement($db);
			$payment->datepaye = $db->jdate($pg['datep']);
			$payment->fk_account = $acc->id;
			$payment->paiementid = (int) $o->id;
			$payment->paiementcode = $pg['code'];
			$payment->num_payment = $pg['num'];
			$payment->note_private = $pg['nota'];
			$payment->amounts[$inv->id] = $pg['monto'];
			$foreign = ($acc->currency_code && $acc->currency_code != $conf->currency);
			if ($foreign) {
				$payment->multicurrency_amounts[$inv->id] = price2num($pg['monto_moneda'], 'MT');
				$payment->multicurrency_code[$inv->id] = $inv->multicurrency_code;
				$payment->multicurrency_tx[$inv->id] = $inv->multicurrency_tx;
				$payment->multicurrency_currency = $inv->multicurrency_code;
			}
			$payid = $payment->create($user);
			if ($payid < 0) {
				$report['errores'][] = $label.': pago de '.$tk['ref_origen'].': '.$payment->error;
				$ok = false;
				break;
			}
			$bankline = $payment->addPaymentToBank($user, 'payment', '(CustomerInvoicePayment)', $acc->id, '', '');
			if ($bankline < 0) {
				$report['errores'][] = $label.': banco de '.$tk['ref_origen'].': '.$payment->error;
				$ok = false;
				break;
			}
			$bts = "'".$db->escape($pg['banco_datec'] ? $pg['banco_datec'] : $pg['datep'])."'";
			$db->query("UPDATE ".MAIN_DB_PREFIX."paiement SET datec = ".$bts." WHERE rowid = ".((int) $payid));
			$db->query("UPDATE ".MAIN_DB_PREFIX."bank SET datec = ".$bts." WHERE rowid = ".((int) $bankline));
			if ($inv->getRemainToPay() == 0) {
				$inv->setPaid($user);
				$inv->setPaymentMethods((int) $o->id);
			}
		}
		if (!$ok) {
			break;
		}
		$db->query("UPDATE ".MAIN_DB_PREFIX."facture SET datec = ".$ts.", date_valid = ".$ts.", date_closing = IF(paye = 1, ".$ts.", date_closing) WHERE rowid = ".((int) $inv->id));
		$db->query("UPDATE ".MAIN_DB_PREFIX."stock_mouvement SET datem = ".$ts." WHERE origintype = 'facture' AND fk_origin = ".((int) $inv->id));
		$report['tickets'][] = $tk['ref_origen'].' → '.$inv->ref.' ('.substr($tk['datec'], 11, 5).', '.price($inv->total_ttc).')';
		$total += (float) $inv->total_ttc;
	}
	$conf->global->MAIN_DISABLE_PDF_AUTOUPDATE = $savpdf;

	$report['notas'][] = $label.': '.count($report['tickets']).' tickets ($'.price($total).') y '.$nbstock.' movimientos de stock.';
}

/**
 * Desactiva y reactiva el módulo para registrar menús, permisos y hooks nuevos,
 * conservando los permisos ya asignados a usuarios y grupos (Dolibarr los borra al
 * desactivar). Las constantes del módulo no se borran (deleteonunactive = 0).
 *
 * @param DoliDB $db
 * @return array{errores:string[],guardados:int,restaurados:int}
 */
function sabrooskiposReactivarModulo($db)
{
	require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
	$out = array('errores' => array(), 'guardados' => 0, 'restaurados' => 0);

	$saved = array();
	foreach (array('user_rights' => 'fk_user', 'usergroup_rights' => 'fk_usergroup') as $table => $col) {
		$resql = $db->query("SELECT t.entity, t.".$col." AS who, d.perms, d.subperms FROM ".MAIN_DB_PREFIX.$table." t JOIN ".MAIN_DB_PREFIX."rights_def d ON d.id = t.fk_id AND d.entity = t.entity WHERE d.module = 'sabrooskipos'");
		while ($resql && ($o = $db->fetch_object($resql))) {
			$saved[] = array($table, $col, (int) $o->entity, (int) $o->who, $o->perms, (string) $o->subperms);
		}
	}
	$out['guardados'] = count($saved);

	$r = unActivateModule('modSabrooskiPOS');
	if ($r) {
		$out['errores'][] = 'Desactivar: '.$r;
	}
	// 1 = no confiar en $conf, que en memoria todavía tiene el módulo como activo.
	$r = activateModule('modSabrooskiPOS', 1, 1);
	foreach ((array) $r['errors'] as $e) {
		$out['errores'][] = 'Activar: '.$e;
	}

	foreach ($saved as $sv) {
		list($table, $col, $entity, $who, $perms, $subperms) = $sv;
		$resql = $db->query("SELECT id FROM ".MAIN_DB_PREFIX."rights_def WHERE module = 'sabrooskipos' AND entity = ".$entity." AND perms = '".$db->escape($perms)."' AND COALESCE(subperms, '') = '".$db->escape($subperms)."'");
		$o = $resql ? $db->fetch_object($resql) : null;
		if ($o) {
			$db->query("INSERT INTO ".MAIN_DB_PREFIX.$table." (entity, ".$col.", fk_id) SELECT ".$entity.", ".$who.", ".((int) $o->id)." FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX.$table." WHERE ".$col." = ".$who." AND fk_id = ".((int) $o->id)." AND entity = ".$entity.")");
			$out['restaurados']++;
		}
	}
	return $out;
}
