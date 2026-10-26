<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/lib/sabrooskipos_bajas.lib.php
 * \ingroup sabrooskipos
 * \brief   Regalías y dañadas: las registra el encargado al cierre, con el PIN de quien
 *          autoriza. Proceso completo: un servido baja sus insumos por receta (kit + mix
 *          de los sabores); un producto con stock propio (bandeja, golosina) baja él mismo.
 *          Sirope, topping y pirulín/oreo no se mueven (se controlan por conteo): el reporte
 *          de insumos los suma al consumo teórico.
 *
 * Movimientos de stock: etiqueta "Regalía: …" o "Dañadas: …" (reglas del reporte del
 * turno) e inventorycode BAJA-…; al anular, la misma etiqueta con "anulada" en positivo.
 * Cada baja queda en llx_sabrooskipos_baja (producto, sabores, motivo, quién autorizó, costo).
 */

require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos_recetas.lib.php';

/**
 * @return array<string,string>
 */
function sabrooskiposBajaTipos()
{
	return array('regalia' => 'Regalía', 'danada' => 'Dañada');
}

/**
 * Prefijo de la etiqueta del movimiento (lo que leen los reportes).
 *
 * @param string $tipo
 * @return string
 */
function sabrooskiposBajaPrefijo($tipo)
{
	return ($tipo == 'regalia' ? 'Regalía' : 'Dañadas');
}

/**
 * Productos que se pueden dar de baja, agrupados por su categoría: los de venta (menos
 * sabores y adicionales) y los insumos.
 *
 * @param DoliDB $db
 * @return array<string,array<int,array{id:int,ref:string,label:string,mix:int,max_sabores:int}>>
 */
function sabrooskiposBajaProductos($db)
{
	global $conf;
	$pre = MAIN_DB_PREFIX;
	$cats = array();
	$resql = $db->query("SELECT cp.fk_product, c.label FROM ".$pre."categorie_product cp JOIN ".$pre."categorie c ON c.rowid = cp.fk_categorie"
		." WHERE c.type = 0 ORDER BY c.fk_parent DESC, c.label");
	while ($resql && ($o = $db->fetch_object($resql))) {
		if (!isset($cats[(int) $o->fk_product])) {
			$cats[(int) $o->fk_product] = (string) $o->label;
		}
	}
	$out = array();
	$resql = $db->query("SELECT p.rowid, p.ref, p.label, p.tosell, e.grupo_sabores, e.max_sabores FROM ".$pre."product p"
		." LEFT JOIN ".$pre."product_extrafields e ON e.fk_object = p.rowid"
		." WHERE p.entity = ".((int) $conf->entity)." AND (p.tosell = 1 OR p.stockable_product = 1) ORDER BY p.ref");
	while ($resql && ($o = $db->fetch_object($resql))) {
		$cat = isset($cats[(int) $o->rowid]) ? $cats[(int) $o->rowid] : 'Otros';
		if (in_array($cat, array('Sabores mix', 'Sabores bandejas', 'Adicional'))) {
			continue;
		}
		if (!$o->tosell && $cat != 'Insumos') {
			continue;
		}
		$out[$cat][(int) $o->rowid] = array('id' => (int) $o->rowid, 'ref' => (string) $o->ref, 'label' => (string) $o->label,
			'mix' => ($o->grupo_sabores === 'mix' ? 1 : 0), 'max_sabores' => (int) $o->max_sabores);
	}
	uksort($out, function ($a, $b) {
		$oa = function_exists('sabrooskiposOrdenCategoria') ? sabrooskiposOrdenCategoria($a) : 0;
		$ob = function_exists('sabrooskiposOrdenCategoria') ? sabrooskiposOrdenCategoria($b) : 0;
		return ($oa - $ob) ?: strcmp($a, $b);
	});
	return $out;
}

/**
 * Sabores del mix (productos SM-* de la categoría de sabores del terminal 1/global).
 *
 * @param DoliDB $db
 * @return array<int,string>  [id => label]
 */
function sabrooskiposBajaSaboresMix($db)
{
	$cat = getDolGlobalInt('SABROOSKIPOS_CATEGORY_FLAVORS');
	$res = array();
	if ($cat > 0) {
		$resql = $db->query("SELECT p.rowid, p.label FROM ".MAIN_DB_PREFIX."categorie_product cp JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = cp.fk_product"
			." WHERE cp.fk_categorie = ".$cat." ORDER BY p.ref");
		while ($resql && ($o = $db->fetch_object($resql))) {
			$res[(int) $o->rowid] = (string) $o->label;
		}
	}
	return $res;
}

/**
 * Lo que baja del stock una baja: un servido, sus insumos por receta; un producto sin
 * receta, él mismo.
 *
 * @param DoliDB $db
 * @param int    $pid
 * @param float  $qty
 * @param string $sabores  ids SM-* separados por coma
 * @return array<int,float>  [fk_product => cantidad]
 */
function sabrooskiposBajaMovimientos($db, $pid, $qty, $sabores)
{
	$r = sabrooskiposReceta($db, $pid);
	if (!$r['kit'] && $r['mix_g'] <= 0) {
		return array((int) $pid => (float) $qty);
	}
	$c = sabrooskiposConsumoLinea($db, $pid, $qty, $sabores);
	return $c['insumos'];
}

/* ---------------------------------------------------------------------- *
 * PIN de autorización (llx_user_param SABROOSKIPOS_PIN, con password_hash)
 * ---------------------------------------------------------------------- */

/**
 * ¿El usuario puede autorizar bajas?
 *
 * @param User $u
 * @return bool
 */
function sabrooskiposPuedeAutorizar($u)
{
	return ((int) (isset($u->status) ? $u->status : $u->statut) == 1 && ($u->admin || $u->hasRight('sabrooskipos', 'bajas', 'autorizar')));
}

/**
 * Usuario que autoriza con este PIN (o null).
 *
 * @param DoliDB $db
 * @param string $pin
 * @return User|null
 */
function sabrooskiposPinAutorizador($db, $pin)
{
	global $conf;
	$pin = trim((string) $pin);
	if (!preg_match('/^\d{4,8}$/', $pin)) {
		return null;
	}
	$resql = $db->query("SELECT fk_user, value FROM ".MAIN_DB_PREFIX."user_param WHERE param = 'SABROOSKIPOS_PIN' AND entity = ".((int) $conf->entity));
	while ($resql && ($o = $db->fetch_object($resql))) {
		if (password_verify($pin, (string) $o->value)) {
			$u = new User($db);
			if ($u->fetch((int) $o->fk_user) > 0) {
				$u->getrights();
				if (sabrooskiposPuedeAutorizar($u)) {
					return $u;
				}
			}
		}
	}
	return null;
}

/**
 * ¿El usuario ya tiene PIN?
 *
 * @param DoliDB $db
 * @param User   $u
 * @return bool
 */
function sabrooskiposPinTiene($db, $u)
{
	global $conf;
	$resql = $db->query("SELECT 1 FROM ".MAIN_DB_PREFIX."user_param WHERE param = 'SABROOSKIPOS_PIN' AND fk_user = ".((int) $u->id)." AND entity = ".((int) $conf->entity));
	return ($resql && $db->num_rows($resql) > 0);
}

/**
 * ¿Es este el PIN del usuario?
 *
 * @param DoliDB $db
 * @param User   $u
 * @param string $pin
 * @return bool
 */
function sabrooskiposPinVerificar($db, $u, $pin)
{
	global $conf;
	$resql = $db->query("SELECT value FROM ".MAIN_DB_PREFIX."user_param WHERE param = 'SABROOSKIPOS_PIN' AND fk_user = ".((int) $u->id)." AND entity = ".((int) $conf->entity));
	$o = $resql ? $db->fetch_object($resql) : null;
	return ($o && password_verify(trim((string) $pin), (string) $o->value));
}

/**
 * Guarda el PIN del usuario (4 a 8 dígitos, distinto del de los demás).
 *
 * @param DoliDB $db
 * @param User   $u
 * @param string $pin
 * @param string $error  (por referencia)
 * @return int  >0 OK, <0 error
 */
function sabrooskiposPinGuardar($db, $u, $pin, &$error)
{
	global $conf;
	$pin = trim((string) $pin);
	if (!preg_match('/^\d{4,8}$/', $pin)) {
		$error = 'El PIN debe tener de 4 a 8 dígitos.';
		return -1;
	}
	$resql = $db->query("SELECT fk_user, value FROM ".MAIN_DB_PREFIX."user_param WHERE param = 'SABROOSKIPOS_PIN' AND entity = ".((int) $conf->entity)." AND fk_user <> ".((int) $u->id));
	while ($resql && ($o = $db->fetch_object($resql))) {
		if (password_verify($pin, (string) $o->value)) {
			$error = 'Ese PIN ya lo usa otra persona. Elige otro.';
			return -1;
		}
	}
	$db->query("DELETE FROM ".MAIN_DB_PREFIX."user_param WHERE param = 'SABROOSKIPOS_PIN' AND fk_user = ".((int) $u->id)." AND entity = ".((int) $conf->entity));
	$ok = $db->query("INSERT INTO ".MAIN_DB_PREFIX."user_param (fk_user, entity, param, value) VALUES (".((int) $u->id).", ".((int) $conf->entity).", 'SABROOSKIPOS_PIN', '".$db->escape(password_hash($pin, PASSWORD_DEFAULT))."')");
	if (!$ok) {
		$error = $db->lasterror();
		return -1;
	}
	return 1;
}

/* ---------------------------------------------------------------------- *
 * Registro y anulación
 * ---------------------------------------------------------------------- */

/**
 * Registra una regalía o dañada. No abre ni cierra transacción.
 *
 * @param DoliDB $db
 * @param User   $user      quien registra (encargado)
 * @param User   $autoriza  quien autorizó con su PIN
 * @param int    $wh        almacén del móvil
 * @param string $fecha     'YYYY-MM-DD'
 * @param string $tipo      'regalia' | 'danada'
 * @param int    $pid
 * @param float  $qty
 * @param int[]  $sabores   ids SM-* (servidos de mix)
 * @param string $motivo    a quién / por qué
 * @param array  $errors    (por referencia)
 * @return int   id de la baja (>0) o <0
 */
function sabrooskiposBajaRegistrar($db, $user, $autoriza, $wh, $fecha, $tipo, $pid, $qty, $sabores, $motivo, &$errors)
{
	global $conf;
	$tipos = sabrooskiposBajaTipos();
	$motivo = trim((string) $motivo);
	$qty = round((float) $qty, 4);
	$sabores = array_values(array_filter(array_map('intval', (array) $sabores)));
	if (!isset($tipos[$tipo])) {
		$errors[] = 'Tipo no válido.';
	}
	if ($wh <= 0) {
		$errors[] = 'Elige el almacén del móvil.';
	}
	if ($qty <= 0) {
		$errors[] = 'La cantidad debe ser mayor que 0.';
	}
	if ($motivo === '') {
		$errors[] = 'Escribe el motivo (a quién se regaló o qué pasó).';
	}
	$p = new Product($db);
	if ($pid <= 0 || $p->fetch($pid) <= 0) {
		$errors[] = 'Elige el producto.';
	}
	if ($errors) {
		return -1;
	}
	$r = sabrooskiposReceta($db, $pid);
	$max = isset($p->array_options['options_max_sabores']) ? (int) $p->array_options['options_max_sabores'] : 0;
	if ($r['mix_g'] > 0 && !$sabores) {
		$errors[] = $p->label.' lleva mix: elige los sabores.';
		return -1;
	}
	if ($r['mix_g'] > 0 && $max > 0 && count($sabores) > $max) {
		$errors[] = $p->label.' admite máximo '.$max.' sabor(es).';
		return -1;
	}
	if ($r['mix_g'] <= 0) {
		$sabores = array();
	}
	$nombres = array();
	foreach ($sabores as $sid) {
		$sp = new Product($db);
		if ($sp->fetch($sid) > 0) {
			$nombres[] = $sp->label;
		}
	}

	$code = 'BAJA-'.dol_print_date(dol_now(), '%Y%m%d%H%M%S').'-'.substr(uniqid(), -4);
	$label = sabrooskiposBajaPrefijo($tipo).': '.$p->label.' ×'.price2num($qty).($nombres ? ' ('.implode(', ', $nombres).')' : '').' – '.$motivo;
	$label = dol_trunc($label, 240, 'right', 'UTF-8', 1);
	foreach (sabrooskiposBajaMovimientos($db, $pid, $qty, implode(',', $sabores)) as $mid => $q) {
		$q = round($q, 4);
		if ($q <= 0) {
			continue;
		}
		$m = new Product($db);
		$m->fetch($mid);
		if ($m->correct_stock($user, $wh, $q, 1, $label, 0, $code) <= 0) {
			$errors[] = $m->ref.': '.($m->error ? $m->error : 'no se pudo descontar (¿no hay existencia en el móvil?)');
			return -1;
		}
	}
	sabrooskiposFecharMovimientos($db, $fecha, '21:00:00', "inventorycode = '".$db->escape($code)."'");

	$c = sabrooskiposCostoReceta($db, $pid);
	$sql = "INSERT INTO ".MAIN_DB_PREFIX."sabrooskipos_baja (entity, datec, fecha, fk_entrepot, tipo, fk_product, qty, sabores, motivo, costo, inventorycode, fk_user_author, fk_user_autoriza, status)"
		." VALUES (".((int) $conf->entity).", '".$db->idate(dol_now())."', '".$db->escape($fecha)."', ".((int) $wh).", '".$db->escape($tipo)."', ".((int) $pid).", ".$qty
		.", '".$db->escape(implode(',', $sabores))."', '".$db->escape(dol_trunc($motivo, 250, 'right', 'UTF-8', 1))."', ".((float) price2num($c['costo'] * $qty, 'MU'))
		.", '".$db->escape($code)."', ".((int) $user->id).", ".((int) $autoriza->id).", 1)";
	if (!$db->query($sql)) {
		$errors[] = $db->lasterror();
		return -1;
	}
	return (int) $db->last_insert_id(MAIN_DB_PREFIX.'sabrooskipos_baja');
}

/**
 * Anula una baja: devuelve al móvil lo que bajó. No abre ni cierra transacción.
 *
 * @param DoliDB $db
 * @param User   $user
 * @param User   $autoriza
 * @param int    $id
 * @param array  $errors  (por referencia)
 * @return int   >0 OK, <0 error
 */
function sabrooskiposBajaAnular($db, $user, $autoriza, $id, &$errors)
{
	$b = sabrooskiposBajaLeer($db, $id);
	if (!$b || $b['status'] != 1) {
		$errors[] = 'La baja no existe o ya está anulada.';
		return -1;
	}
	$resql = $db->query("SELECT fk_product, fk_entrepot, SUM(value) AS v, MIN(label) AS label FROM ".MAIN_DB_PREFIX."stock_mouvement"
		." WHERE inventorycode = '".$db->escape($b['inventorycode'])."' GROUP BY fk_product, fk_entrepot HAVING ABS(SUM(value)) > 0.0001");
	$movs = array();
	while ($resql && ($o = $db->fetch_object($resql))) {
		$movs[] = $o;
	}
	$code = $b['inventorycode'].'-A';
	foreach ($movs as $o) {
		$label = dol_trunc(preg_replace('/^(Regalía|Dañadas):/u', '$1 anulada:', (string) $o->label), 240, 'right', 'UTF-8', 1);
		$m = new Product($db);
		$m->fetch((int) $o->fk_product);
		if ($m->correct_stock($user, (int) $o->fk_entrepot, abs((float) $o->v), ((float) $o->v < 0 ? 0 : 1), $label, 0, $code) <= 0) {
			$errors[] = $m->ref.': '.$m->error;
			return -1;
		}
	}
	sabrooskiposFecharMovimientos($db, $b['fecha'], '21:30:00', "inventorycode = '".$db->escape($code)."'");
	$ok = $db->query("UPDATE ".MAIN_DB_PREFIX."sabrooskipos_baja SET status = 9, date_anula = '".$db->idate(dol_now())."', fk_user_anula = ".((int) $user->id)
		.", fk_user_autoriza_anula = ".((int) $autoriza->id)." WHERE rowid = ".((int) $id));
	if (!$ok) {
		$errors[] = $db->lasterror();
		return -1;
	}
	return 1;
}

/**
 * Una baja por id.
 *
 * @param DoliDB $db
 * @param int    $id
 * @return array|null
 */
function sabrooskiposBajaLeer($db, $id)
{
	$l = sabrooskiposBajasDia($db, 0, '', false, $id);
	return $l ? reset($l) : null;
}

/**
 * Bajas de un móvil en un día (o una por id).
 *
 * @param DoliDB $db
 * @param int    $wh
 * @param string $fecha
 * @param bool   $soloActivas
 * @param int    $id
 * @return array<int,array>
 */
function sabrooskiposBajasDia($db, $wh, $fecha, $soloActivas = true, $id = 0)
{
	global $conf;
	$pre = MAIN_DB_PREFIX;
	$sql = "SELECT b.*, p.ref, p.label, ua.login AS autoriza, ur.login AS registra, un.login AS anula, uan.login AS autoriza_anula"
		." FROM ".$pre."sabrooskipos_baja b JOIN ".$pre."product p ON p.rowid = b.fk_product"
		." LEFT JOIN ".$pre."user ua ON ua.rowid = b.fk_user_autoriza LEFT JOIN ".$pre."user ur ON ur.rowid = b.fk_user_author"
		." LEFT JOIN ".$pre."user un ON un.rowid = b.fk_user_anula LEFT JOIN ".$pre."user uan ON uan.rowid = b.fk_user_autoriza_anula"
		." WHERE b.entity = ".((int) $conf->entity);
	if ($id > 0) {
		$sql .= " AND b.rowid = ".((int) $id);
	} else {
		$sql .= " AND b.fk_entrepot = ".((int) $wh)." AND b.fecha = '".$db->escape($fecha)."'".($soloActivas ? " AND b.status = 1" : "");
	}
	$sql .= " ORDER BY b.rowid";
	$out = array();
	$resql = $db->query($sql);
	if (!$resql) {
		return $out; // tabla aún no creada (módulo sin reactivar)
	}
	$sab = null;
	while ($o = $db->fetch_object($resql)) {
		$nombres = array();
		if ((string) $o->sabores !== '') {
			if ($sab === null) {
				$sab = sabrooskiposBajaSaboresMix($db);
			}
			foreach (explode(',', $o->sabores) as $sid) {
				$nombres[] = isset($sab[(int) $sid]) ? $sab[(int) $sid] : '#'.$sid;
			}
		}
		$out[(int) $o->rowid] = array('id' => (int) $o->rowid, 'fecha' => (string) $o->fecha, 'datec' => $db->jdate($o->datec), 'wh' => (int) $o->fk_entrepot,
			'tipo' => (string) $o->tipo, 'fk_product' => (int) $o->fk_product, 'ref' => (string) $o->ref, 'label' => (string) $o->label,
			'qty' => (float) $o->qty, 'sabores' => (string) $o->sabores, 'sabores_txt' => implode(', ', $nombres), 'motivo' => (string) $o->motivo,
			'costo' => (float) $o->costo, 'inventorycode' => (string) $o->inventorycode, 'status' => (int) $o->status,
			'autoriza' => (string) $o->autoriza, 'registra' => (string) $o->registra, 'anula' => (string) $o->anula, 'autoriza_anula' => (string) $o->autoriza_anula);
	}
	return $out;
}
