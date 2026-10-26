<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/lib/sabrooskipos_recetas.lib.php
 * \ingroup sabrooskipos
 * \brief   Recetas de los servidos (insumos por venta), costos e insumos del camión.
 *
 * Modelo:
 *  - Insumos fijos de cada servido (vaso, cono, cucharilla, servilleta, golosina del
 *    especial…) = kit nativo de Dolibarr (llx_product_association, incdec = 1): el
 *    TakePOS los descuenta solo al validar el ticket (PRODUIT_SOUSPRODUITS = 1).
 *  - Mix: gramos_porcion del producto, repartidos entre los sabores elegidos; cada sabor
 *    SM-* es un "kit" de mix (incdec = 0, solo informativo) con su fracción. Lo descuenta
 *    el trigger del módulo (core/triggers).
 *  - Sirope, topping y pirulín/oreo (el cliente elige en la barra): no se descuentan al
 *    vender; se calcula el consumo teórico y al cierre se compara con lo contado.
 *  - Costos: cost_price de cada insumo; costo de referencia por gramo para sirope y
 *    topping y por unidad para pirulín/oreo (recetas_sabrooski.json → referencias).
 *
 * Fuente: recetas_sabrooski.json (derivado de "Estructura de Costos Sistema.xlsx").
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once __DIR__.'/sabrooskipos.lib.php';

/**
 * Lee el JSON de recetas del módulo.
 *
 * @param string $file  Ruta (vacío = recetas_sabrooski.json del módulo)
 * @return array|null
 */
function sabrooskiposRecetasLeer($file = '')
{
	if ($file === '') {
		$file = dirname(__DIR__).'/recetas_sabrooski.json';
	}
	if (!is_readable($file)) {
		return null;
	}
	$data = json_decode(file_get_contents($file), true);
	return is_array($data) ? $data : null;
}

/**
 * Asegura los extrafields de recetas (producto) y de sabores (línea de factura).
 * Debe llamarse FUERA de una transacción: addExtraField() hace ALTER TABLE.
 *
 * @param DoliDB $db
 * @return void
 */
function sabrooskiposRecetasEnsureExtrafields($db)
{
	$cond = 'isModEnabled("sabrooskipos")';
	$extrafields = new ExtraFields($db);

	$existing = $extrafields->fetch_name_optionals_label('product');
	if (!is_array($existing)) {
		$existing = array();
	}
	$defs = array(
		array('gramos_sirope', 'Gramos de sirope (receta)', 'double', 105, '24,4'),
		array('gramos_topping', 'Gramos de topping (receta)', 'double', 106, '24,4'),
		array('uds_pirulin_oreo', 'Pirulín/Oreo (receta, uds)', 'double', 107, '24,4'),
		array('unidad_control', 'Unidad de control', 'varchar', 108, '8'),
	);
	foreach ($defs as $d) {
		if (!isset($existing[$d[0]])) {
			$extrafields->addExtraField($d[0], $d[1], $d[2], $d[3], $d[4], 'product', 0, 0, '', '', 1, '', 0, '', '', '', 'sabrooskipos@sabrooskipos', $cond);
		}
	}

	$existing = $extrafields->fetch_name_optionals_label('facturedet');
	if (!is_array($existing)) {
		$existing = array();
	}
	if (!isset($existing['sabrooski_sabores'])) {
		// Ids de los sabores (productos SM-*) elegidos en el popup, separados por coma.
		$extrafields->addExtraField('sabrooski_sabores', 'Sabores (popup)', 'varchar', 100, '255', 'facturedet', 0, 0, '', '', 0, '', 0, '', '', '', 'sabrooskipos@sabrooskipos', $cond);
	}
}

/**
 * Deja la asociación (kit) de un producto exactamente como $hijos.
 *
 * @param DoliDB $db
 * @param int    $padre   id del producto padre
 * @param array  $hijos   [id hijo => cantidad]
 * @param int    $incdec  1 = el hijo se descuenta al mover el padre, 0 = informativo
 * @return bool  true si cambió algo
 */
function sabrooskiposSyncKit($db, $padre, $hijos, $incdec)
{
	$pre = MAIN_DB_PREFIX;
	$actual = array();
	$resql = $db->query("SELECT fk_product_fils, qty, incdec FROM ".$pre."product_association WHERE fk_product_pere = ".((int) $padre));
	while ($resql && ($o = $db->fetch_object($resql))) {
		$actual[(int) $o->fk_product_fils] = array((float) $o->qty, (int) $o->incdec);
	}
	$cambio = false;
	foreach ($actual as $hijo => $v) {
		if (!isset($hijos[$hijo])) {
			$db->query("DELETE FROM ".$pre."product_association WHERE fk_product_pere = ".((int) $padre)." AND fk_product_fils = ".((int) $hijo));
			$cambio = true;
		}
	}
	$rang = 0;
	foreach ($hijos as $hijo => $qty) {
		$rang++;
		if (!isset($actual[$hijo])) {
			$db->query("INSERT INTO ".$pre."product_association (fk_product_pere, fk_product_fils, qty, incdec, rang) VALUES (".((int) $padre).", ".((int) $hijo).", ".((float) $qty).", ".((int) $incdec).", ".$rang.")");
			$cambio = true;
		} elseif (abs($actual[$hijo][0] - $qty) > 0.00001 || $actual[$hijo][1] != $incdec) {
			$db->query("UPDATE ".$pre."product_association SET qty = ".((float) $qty).", incdec = ".((int) $incdec)." WHERE fk_product_pere = ".((int) $padre)." AND fk_product_fils = ".((int) $hijo));
			$cambio = true;
		}
	}
	return $cambio;
}

/**
 * Aplica el kit de un producto. Si los hijos se descuentan (incdec = 1), el padre deja de
 * llevar stock propio: su existencia se pone en 0 antes de crear el kit (después Dolibarr
 * ya no movería el stock del padre) y queda sin control de stock.
 *
 * @param DoliDB  $db
 * @param User    $user
 * @param Product $p       producto padre
 * @param array   $hijos   [id hijo => cantidad]
 * @param int     $incdec
 * @param array   $report  (por referencia: notas / errores)
 * @return bool   true si el kit cambió
 */
function sabrooskiposAplicarKit($db, $user, $p, $hijos, $incdec, &$report)
{
	$pre = MAIN_DB_PREFIX;
	if ($hijos && $incdec) {
		$resql = $db->query("SELECT fk_entrepot, reel FROM ".$pre."product_stock WHERE fk_product = ".((int) $p->id)." AND reel <> 0");
		$saldos = array();
		while ($resql && ($o = $db->fetch_object($resql))) {
			$saldos[] = $o;
		}
		if ($saldos && (int) $p->stockable_product == 0) {
			// Sin control de stock Dolibarr no movería la existencia: se reactiva para
			// poder dejarla en 0 (pasa en la importación, que ya trae stockable = 0).
			$db->query("UPDATE ".$pre."product SET stockable_product = 1 WHERE rowid = ".((int) $p->id));
			$p->stockable_product = 1;
		}
		foreach ($saldos as $o) {
			$r1 = $p->correct_stock($user, (int) $o->fk_entrepot, abs((float) $o->reel), ((float) $o->reel > 0 ? 1 : 0), 'Ajuste: '.$p->ref.' pasa a receta (se controlan sus insumos)', 0, 'RECETAS');
			if ($r1 <= 0) {
				$report['errores'][] = 'Stock de '.$p->ref.': '.$p->error;
			} else {
				$report['notas'][] = $p->ref.': existencia '.((float) $o->reel).' puesta en 0 (pasa a receta)';
			}
		}
		if ((int) $p->stockable_product != 0) {
			$db->query("UPDATE ".$pre."product SET stockable_product = 0 WHERE rowid = ".((int) $p->id));
		}
	}
	return sabrooskiposSyncKit($db, $p->id, $hijos, $incdec);
}

/**
 * Carga (idempotente) insumos, costos, productos nuevos y recetas desde el JSON.
 * Llamar sabrooskiposRecetasEnsureExtrafields() antes, fuera de la transacción.
 *
 * @param DoliDB $db
 * @param User   $user
 * @param array  $data    Contenido de recetas_sabrooski.json
 * @param array  $report  ['creados'=>[], 'actualizados'=>[], 'kits'=>[], 'notas'=>[], 'errores'=>[]]
 * @return int   1 OK, 0 error
 */
function sabrooskiposCargarRecetas($db, $user, $data, &$report)
{
	global $conf;
	$pre = MAIN_DB_PREFIX;
	foreach (array('creados', 'actualizados', 'kits', 'notas', 'errores', 'categorias_creadas') as $k) {
		if (!isset($report[$k])) {
			$report[$k] = array();
		}
	}
	$tva = (float) (getDolGlobalString('SABROOSKIPOS_TVA_TX') !== '' ? getDolGlobalString('SABROOSKIPOS_TVA_TX') : 16);

	// ---- Categorías ----
	$catid = array();
	$resql = $db->query("SELECT rowid, label FROM ".$pre."categorie WHERE type = 0 AND entity = ".((int) $conf->entity));
	while ($resql && ($o = $db->fetch_object($resql))) {
		$catid[$o->label] = (int) $o->rowid;
	}
	foreach ((array) $data['categorias'] as $c) {
		if (!isset($catid[$c['label']])) {
			$parent = isset($catid[$c['parent']]) ? $catid[$c['parent']] : 0;
			$catid[$c['label']] = sabrooskiposEnsureCategory($db, $c['label'], $parent, $user, $report);
		}
	}

	// Producto por ref (crea si falta). Devuelve Product o null.
	$getProd = function ($ref, $label, $cat, $tosell, $tobuy, $stockable) use ($db, $user, &$report, $catid, $tva) {
		$p = new Product($db);
		if ($p->fetch(0, $ref) > 0) {
			return $p;
		}
		$p = new Product($db);
		$p->ref = $ref;
		$p->label = $label;
		$p->type = 0;
		$p->status = $tosell;
		$p->status_buy = $tobuy;
		$p->price_base_type = 'TTC';
		$p->tva_tx = $tva;
		$p->stockable_product = $stockable;
		if ($p->create($user) <= 0) {
			$report['errores'][] = 'Producto '.$ref.': '.$p->error;
			return null;
		}
		$p->updatePrice(0, 'TTC', $user, $tva);
		if (!empty($cat) && isset($catid[$cat])) {
			$p->setCategories(array($catid[$cat]));
		}
		$report['creados'][] = $ref.' '.$label;
		$p->fetch($p->id);
		return $p;
	};

	// Costo y unidad de control (extrafield) de un producto.
	$setCosto = function ($p, $costo, $unidad) use ($db, &$report) {
		$cambio = false;
		if (abs((float) $p->cost_price - (float) $costo) > 0.0000001) {
			$db->query("UPDATE ".MAIN_DB_PREFIX."product SET cost_price = ".((float) $costo)." WHERE rowid = ".((int) $p->id));
			$cambio = true;
		}
		$p->cost_price = (float) $costo; // para que un $p->update() posterior no lo revierta
		$p->fetch_optionals();
		if ((string) (isset($p->array_options['options_unidad_control']) ? $p->array_options['options_unidad_control'] : '') !== (string) $unidad) {
			$p->array_options['options_unidad_control'] = $unidad;
			$p->insertExtraFields();
			$cambio = true;
		}
		return $cambio;
	};

	// ---- Insumos ----
	foreach ((array) $data['insumos'] as $i) {
		$p = $getProd($i['ref'], $i['label'], $i['categoria'], 0, 1, 1);
		if (!$p) {
			continue;
		}
		$cambio = $setCosto($p, $i['costo'], $i['unidad']);
		$desc = ($i['categoria'] == 'Topping' ? 'Topping' : 'Insumo').'. Inventario en '.($i['unidad'] == 'g' ? 'gramos' : 'uds').'.';
		if ($p->label !== $i['label'] || $p->description !== $desc || (int) $p->status != 0 || (int) $p->status_buy != 1) {
			$p->label = $i['label'];
			$p->description = $desc;
			$p->status = 0;
			$p->status_buy = 1;
			$p->update($p->id, $user);
			$cambio = true;
		}
		if (isset($catid[$i['categoria']])) {
			$p->setCategories(array($catid[$i['categoria']]));
		}
		if ((int) $p->stockable_product != 1) {
			$db->query("UPDATE ".$pre."product SET stockable_product = 1 WHERE rowid = ".((int) $p->id));
			$cambio = true;
		}
		if ($cambio) {
			$report['actualizados'][] = $i['ref'].' '.$i['label'].' (costo '.$i['costo'].' / '.$i['unidad'].')';
		}
	}

	// ---- Productos nuevos (fuera de venta hasta tener precio) ----
	foreach ((array) $data['productos_nuevos'] as $n) {
		$p = new Product($db);
		if ($p->fetch(0, $n['ref']) > 0) {
			continue; // ya existe: no se tocan precio ni venta
		}
		$p = $getProd($n['ref'], $n['label'], $n['categoria'], 0, 0, (int) $n['stockable']);
		if ($p) {
			$p->description = 'Precio por confirmar: activar la venta al cargar el precio.';
			$p->update($p->id, $user);
		}
	}

	// ---- Costos de productos existentes ----
	foreach ((array) $data['costos'] as $ref => $c) {
		$p = new Product($db);
		if ($p->fetch(0, $ref) <= 0) {
			$report['errores'][] = 'Costo: no existe '.$ref;
			continue;
		}
		if ($setCosto($p, $c['costo'], $c['unidad'])) {
			$report['actualizados'][] = $ref.' '.$p->label.' (costo '.$c['costo'].' / '.$c['unidad'].')';
		}
	}

	// ---- Recetas ----
	$idByRef = function ($ref) use ($db) {
		$p = new Product($db);
		return ($p->fetch(0, $ref) > 0 ? (int) $p->id : 0);
	};
	foreach ((array) $data['recetas'] as $r) {
		$p = new Product($db);
		if ($p->fetch(0, $r['ref']) <= 0) {
			$report['errores'][] = 'Receta: no existe '.$r['ref'];
			continue;
		}
		$hijos = array();
		foreach ((array) $r['kit'] as $hr => $qty) {
			$hid = $idByRef($hr);
			if ($hid <= 0) {
				$report['errores'][] = 'Receta '.$r['ref'].': no existe el insumo '.$hr;
				continue 2;
			}
			$hijos[$hid] = (float) $qty;
		}

		if (sabrooskiposAplicarKit($db, $user, $p, $hijos, 1, $report)) {
			$report['kits'][] = $r['ref'].' '.$p->label.': '.count($hijos).' insumos';
		}

		$p->fetch_optionals();
		$ef = array('gramos_porcion' => (string) $r['mix_g'], 'gramos_sirope' => (float) $r['sirope_g'],
			'gramos_topping' => (float) $r['topping_g'], 'uds_pirulin_oreo' => (float) $r['pirulin_oreo']);
		if ($r['mix_g'] > 0) {
			// Los servidos llevan sabor de la máquina.
			$ef['grupo_sabores'] = 'mix';
			if (empty($p->array_options['options_max_sabores'])) {
				$ef['max_sabores'] = 1;
			}
		}
		$cambio = false;
		foreach ($ef as $k => $v) {
			$old = isset($p->array_options['options_'.$k]) ? $p->array_options['options_'.$k] : null;
			if ((string) $old !== (string) $v && !(is_numeric($old) && is_numeric($v) && abs((float) $old - (float) $v) < 0.00001)) {
				$p->array_options['options_'.$k] = $v;
				$cambio = true;
			}
		}
		if ($cambio) {
			$p->insertExtraFields();
			$report['actualizados'][] = $r['ref'].' '.$p->label.' (receta: mix '.$r['mix_g'].' g, sirope '.$r['sirope_g'].' g, topping '.$r['topping_g'].' g, pirulín/oreo '.$r['pirulin_oreo'].')';
		}
	}

	// ---- Sabores de la máquina: reparto del mix (kit informativo, incdec = 0) ----
	foreach ((array) $data['sabores_mix'] as $ref => $comp) {
		$pid = $idByRef($ref);
		if ($pid <= 0) {
			$report['errores'][] = 'Sabor: no existe '.$ref;
			continue;
		}
		$hijos = array();
		foreach ($comp as $mref => $frac) {
			$mid = $idByRef($mref);
			if ($mid <= 0) {
				$report['errores'][] = 'Sabor '.$ref.': no existe '.$mref;
				continue 2;
			}
			$hijos[$mid] = (float) $frac;
		}
		if (sabrooskiposSyncKit($db, $pid, $hijos, 0)) {
			$report['kits'][] = $ref.' (sabor): '.implode(' + ', array_map(function ($r, $f) {
				return $f.' '.$r;
			}, array_keys($comp), $comp));
		}
	}

	// ---- Configuración ----
	if (!getDolGlobalInt('PRODUIT_SOUSPRODUITS')) {
		dolibarr_set_const($db, 'PRODUIT_SOUSPRODUITS', '1', 'chaine', 0, '', $conf->entity);
		$report['notas'][] = 'PRODUIT_SOUSPRODUITS = 1 (kits activos)';
	}
	$refs = (array) $data['referencias'];
	foreach (array('costo_g_mix', 'costo_g_sirope', 'costo_g_topping', 'costo_pirulin_oreo') as $k) {
		$name = 'SABROOSKIPOS_'.strtoupper($k);
		if (isset($refs[$k]) && getDolGlobalString($name) !== (string) $refs[$k]) {
			dolibarr_set_const($db, $name, (string) $refs[$k], 'chaine', 0, '', $conf->entity);
			$report['notas'][] = $name.' = '.$refs[$k];
		}
	}

	return count($report['errores']) ? 0 : 1;
}

/**
 * Receta de un producto (por id), con caché.
 *
 * @param DoliDB $db
 * @param int    $pid
 * @return array{mix_g:float,sirope_g:float,topping_g:float,pirulin_oreo:float,kit:array<int,float>,ref:string,label:string}
 */
function sabrooskiposReceta($db, $pid)
{
	static $cache = array();
	$pid = (int) $pid;
	if (isset($cache[$pid])) {
		return $cache[$pid];
	}
	$pre = MAIN_DB_PREFIX;
	$r = array('mix_g' => 0.0, 'sirope_g' => 0.0, 'topping_g' => 0.0, 'pirulin_oreo' => 0.0, 'kit' => array(), 'ref' => '', 'label' => '');
	$resql = $db->query("SELECT p.ref, p.label, e.gramos_porcion, e.gramos_sirope, e.gramos_topping, e.uds_pirulin_oreo, e.grupo_sabores
		FROM ".$pre."product p LEFT JOIN ".$pre."product_extrafields e ON e.fk_object = p.rowid WHERE p.rowid = ".$pid);
	if ($resql && ($o = $db->fetch_object($resql))) {
		$r['ref'] = (string) $o->ref;
		$r['label'] = (string) $o->label;
		// gramos_porcion es gramos de mix solo en los servidos (grupo mix); en bandejas es informativo.
		$r['mix_g'] = ($o->grupo_sabores === 'mix') ? (float) $o->gramos_porcion : 0.0;
		$r['sirope_g'] = (float) $o->gramos_sirope;
		$r['topping_g'] = (float) $o->gramos_topping;
		$r['pirulin_oreo'] = (float) $o->uds_pirulin_oreo;
	}
	$resql = $db->query("SELECT fk_product_fils, qty FROM ".$pre."product_association WHERE incdec = 1 AND fk_product_pere = ".$pid);
	while ($resql && ($o = $db->fetch_object($resql))) {
		$r['kit'][(int) $o->fk_product_fils] = (float) $o->qty;
	}
	$cache[$pid] = $r;
	return $r;
}

/**
 * Reparto del mix por insumo para los sabores elegidos (partes iguales entre sabores y,
 * dentro de cada sabor, según su fracción).
 *
 * @param DoliDB $db
 * @param array  $saborIds  ids de productos SM-*
 * @return array<int,float>  [id insumo mix => fracción del total]
 */
function sabrooskiposRepartoMix($db, $saborIds)
{
	$saborIds = array_values(array_filter(array_map('intval', (array) $saborIds)));
	$res = array();
	if (!$saborIds) {
		return $res;
	}
	$n = count($saborIds);
	foreach ($saborIds as $sid) {
		$resql = $db->query("SELECT fk_product_fils, qty FROM ".MAIN_DB_PREFIX."product_association WHERE fk_product_pere = ".((int) $sid));
		while ($resql && ($o = $db->fetch_object($resql))) {
			$mid = (int) $o->fk_product_fils;
			$res[$mid] = (isset($res[$mid]) ? $res[$mid] : 0) + ((float) $o->qty) / $n;
		}
	}
	return $res;
}

/**
 * Consumo teórico de insumos de una línea de venta.
 *
 * @param DoliDB $db
 * @param int    $pid       producto vendido
 * @param float  $qty       cantidad
 * @param string $sabores   ids SM-* separados por coma (extrafield de la línea)
 * @return array{insumos:array<int,float>,sirope_g:float,topping_g:float,pirulin_oreo:float}
 */
function sabrooskiposConsumoLinea($db, $pid, $qty, $sabores)
{
	$r = sabrooskiposReceta($db, $pid);
	$out = array('insumos' => array(), 'sirope_g' => $r['sirope_g'] * $qty, 'topping_g' => $r['topping_g'] * $qty,
		'pirulin_oreo' => $r['pirulin_oreo'] * $qty);
	foreach ($r['kit'] as $hid => $hq) {
		$out['insumos'][$hid] = (isset($out['insumos'][$hid]) ? $out['insumos'][$hid] : 0) + $hq * $qty;
	}
	if ($r['mix_g'] > 0) {
		foreach (sabrooskiposRepartoMix($db, explode(',', (string) $sabores)) as $mid => $frac) {
			$out['insumos'][$mid] = (isset($out['insumos'][$mid]) ? $out['insumos'][$mid] : 0) + $r['mix_g'] * $qty * $frac;
		}
	}
	return $out;
}

/**
 * Costo de receta de una unidad del producto (USD).
 *
 * Insumos fijos × costo + mix × costo/g de referencia + sirope y topping × costo/g de
 * referencia + pirulín/oreo × costo de referencia. Si el producto no tiene receta, su
 * propio cost_price (congelados).
 *
 * @param DoliDB $db
 * @param int    $pid
 * @return array{costo:float,detalle:array<string,float>}
 */
function sabrooskiposCostoReceta($db, $pid)
{
	$r = sabrooskiposReceta($db, $pid);
	$det = array();
	foreach ($r['kit'] as $hid => $hq) {
		$p = new Product($db);
		$p->fetch($hid);
		$det[$p->label.' × '.$hq] = (float) $p->cost_price * $hq;
	}
	if ($r['mix_g'] > 0) {
		$det['Mix '.$r['mix_g'].' g'] = $r['mix_g'] * (float) getDolGlobalString('SABROOSKIPOS_COSTO_G_MIX', '0.002044');
	}
	if ($r['sirope_g'] > 0) {
		$det['Sirope '.$r['sirope_g'].' g'] = $r['sirope_g'] * (float) getDolGlobalString('SABROOSKIPOS_COSTO_G_SIROPE', '0.0096');
	}
	if ($r['topping_g'] > 0) {
		$det['Topping '.$r['topping_g'].' g'] = $r['topping_g'] * (float) getDolGlobalString('SABROOSKIPOS_COSTO_G_TOPPING', '0.0126');
	}
	if ($r['pirulin_oreo'] > 0) {
		$det['Pirulín/Oreo × '.$r['pirulin_oreo']] = $r['pirulin_oreo'] * (float) getDolGlobalString('SABROOSKIPOS_COSTO_PIRULIN_OREO', '0.178');
	}
	if (!$det) {
		$p = new Product($db);
		$p->fetch($pid);
		$det['Costo del producto'] = (float) $p->cost_price;
	}
	return array('costo' => array_sum($det), 'detalle' => $det);
}

/**
 * Sabores (ids SM-*) guardados en cada línea de una factura.
 *
 * @param DoliDB $db
 * @param int    $facid
 * @return array<int,string>  [id línea => "12,14"]
 */
function sabrooskiposSaboresFactura($db, $facid)
{
	$res = array();
	$resql = $db->query("SELECT d.rowid, e.sabrooski_sabores FROM ".MAIN_DB_PREFIX."facturedet d
		JOIN ".MAIN_DB_PREFIX."facturedet_extrafields e ON e.fk_object = d.rowid WHERE d.fk_facture = ".((int) $facid));
	while ($resql && ($o = $db->fetch_object($resql))) {
		if ((string) $o->sabrooski_sabores !== '') {
			$res[(int) $o->rowid] = (string) $o->sabrooski_sabores;
		}
	}
	return $res;
}

/**
 * Mix que lleva una factura TakePOS, por insumo, según la receta y los sabores de cada
 * línea. En una nota de crédito sin sabores propios se toman los de la factura origen
 * (misma línea de producto).
 *
 * @param DoliDB  $db
 * @param Facture $fac
 * @return array<int,float>  [id insumo mix => gramos]
 */
function sabrooskiposMixFactura($db, $fac)
{
	$sabores = sabrooskiposSaboresFactura($db, $fac->id);
	$origen = array();
	if ($fac->type == Facture::TYPE_CREDIT_NOTE && $fac->fk_facture_source > 0) {
		$resql = $db->query("SELECT d.fk_product, e.sabrooski_sabores FROM ".MAIN_DB_PREFIX."facturedet d
			JOIN ".MAIN_DB_PREFIX."facturedet_extrafields e ON e.fk_object = d.rowid
			WHERE d.fk_facture = ".((int) $fac->fk_facture_source)." AND e.sabrooski_sabores <> ''");
		while ($resql && ($o = $db->fetch_object($resql))) {
			$origen[(int) $o->fk_product][] = (string) $o->sabrooski_sabores;
		}
	}
	$mix = array();
	foreach ((array) $fac->lines as $l) {
		if (empty($l->fk_product)) {
			continue;
		}
		$r = sabrooskiposReceta($db, $l->fk_product);
		if ($r['mix_g'] <= 0) {
			continue;
		}
		$s = isset($sabores[(int) $l->id]) ? $sabores[(int) $l->id] : '';
		if ($s === '' && !empty($origen[(int) $l->fk_product])) {
			$s = array_shift($origen[(int) $l->fk_product]);
		}
		foreach (sabrooskiposRepartoMix($db, explode(',', $s)) as $mid => $frac) {
			$mix[$mid] = (isset($mix[$mid]) ? $mix[$mid] : 0) + $r['mix_g'] * abs((float) $l->qty) * $frac;
		}
	}
	return $mix;
}

/**
 * Almacén del que descuenta el TakePOS esta factura (0 si no descuenta stock), con la
 * misma regla que takepos/invoice.php: terminal = pos_source, sin
 * CASHDESK_NO_DECREASE_STOCK<term>, almacén CASHDESK_ID_WAREHOUSE<term>.
 *
 * @param Facture $fac
 * @return int
 */
function sabrooskiposAlmacenTakepos($fac)
{
	if ($fac->module_source !== 'takepos' || !isModEnabled('stock') || isModEnabled('productbatch')) {
		return 0;
	}
	$term = (string) $fac->pos_source;
	if (getDolGlobalString('CASHDESK_NO_DECREASE_STOCK'.$term) == '1') {
		return 0;
	}
	return getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$term);
}

/**
 * Mueve el mix de una factura TakePOS: al validar descuenta (nota de crédito: devuelve)
 * y al anular/borrar revierte lo neto que ya se movió para esa factura.
 *
 * @param DoliDB  $db
 * @param User    $user
 * @param Facture $fac
 * @param string  $modo  'validar' | 'revertir'
 * @return int    >= 0 OK (movimientos creados), < 0 error
 */
function sabrooskiposMoverMixFactura($db, $user, $fac, $modo)
{
	require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';
	$etiqueta = 'Mix receta '.$fac->ref;
	$n = 0;

	if ($modo == 'validar') {
		$wh = sabrooskiposAlmacenTakepos($fac);
		if ($wh <= 0) {
			return 0;
		}
		$movs = array();
		foreach (sabrooskiposMixFactura($db, $fac) as $mid => $g) {
			$movs[] = array($mid, $wh, round($g, 4), ($fac->type == Facture::TYPE_CREDIT_NOTE ? 'entrada' : 'salida'));
		}
	} else {
		// Lo neto ya movido para esta factura, por insumo y almacén, se deshace.
		$movs = array();
		$resql = $db->query("SELECT fk_product, fk_entrepot, SUM(value) AS v FROM ".MAIN_DB_PREFIX."stock_mouvement
			WHERE origintype = 'facture' AND fk_origin = ".((int) $fac->id)." AND label LIKE 'Mix receta %'
			GROUP BY fk_product, fk_entrepot HAVING ABS(SUM(value)) > 0.0001");
		while ($resql && ($o = $db->fetch_object($resql))) {
			$movs[] = array((int) $o->fk_product, (int) $o->fk_entrepot, abs((float) $o->v), ((float) $o->v < 0 ? 'entrada' : 'salida'));
		}
		$etiqueta = 'Mix receta '.$fac->ref.' (reversión)';
	}

	foreach ($movs as $m) {
		if ($m[2] <= 0) {
			continue;
		}
		$mv = new MouvementStock($db);
		$mv->setOrigin('facture', $fac->id);
		if ($m[3] == 'salida') {
			$r = $mv->livraison($user, $m[0], $m[1], $m[2], 0, $etiqueta);
		} else {
			$r = $mv->reception($user, $m[0], $m[1], $m[2], 0, $etiqueta);
		}
		if ($r < 0) {
			return -1;
		}
		$n++;
	}
	return $n;
}

/* ====================================================================== *
 * Carga del camión y cierre (conteo)
 * ====================================================================== */

/**
 * Insumos y congelados de la planilla de carga, agrupados como en el JSON (carga[]).
 * Solo productos con stock propio (los padres de kit no: se controlan por sus insumos).
 *
 * @param DoliDB $db
 * @param array  $data  recetas_sabrooski.json
 * @return array<int,array{titulo:string,unidad:string,factor:float,items:array}>
 */
function sabrooskiposCargaGrupos($db, $data)
{
	global $conf;
	$pre = MAIN_DB_PREFIX;
	$vistos = array();
	$grupos = array();
	foreach ((array) $data['carga'] as $g) {
		$where = array();
		if (!empty($g['refs'])) {
			$where[] = "p.ref IN ('".implode("','", array_map(array($db, 'escape'), $g['refs']))."')";
		}
		if (!empty($g['categorias'])) {
			$where[] = "p.rowid IN (SELECT cp.fk_product FROM ".$pre."categorie_product cp JOIN ".$pre."categorie c ON c.rowid = cp.fk_categorie"
				." WHERE c.label IN ('".implode("','", array_map(array($db, 'escape'), $g['categorias']))."'))";
		}
		$items = array();
		$sql = "SELECT p.rowid, p.ref, p.label, e.unidad_control FROM ".$pre."product p"
			." LEFT JOIN ".$pre."product_extrafields e ON e.fk_object = p.rowid"
			." WHERE p.entity = ".((int) $conf->entity)." AND p.stockable_product = 1 AND (".implode(' OR ', $where).")"
			." AND NOT EXISTS (SELECT 1 FROM ".$pre."product_association a WHERE a.fk_product_pere = p.rowid AND a.incdec = 1)"
			." AND (p.tosell = 1 OR p.tobuy = 1)"
			." ORDER BY p.ref";
		$resql = $db->query($sql);
		while ($resql && ($o = $db->fetch_object($resql))) {
			if (isset($vistos[(int) $o->rowid])) {
				continue;
			}
			$vistos[(int) $o->rowid] = 1;
			$unidad = $g['unidad'] !== '' ? $g['unidad'] : ((string) $o->unidad_control !== '' ? (string) $o->unidad_control : 'uds');
			$items[] = array('id' => (int) $o->rowid, 'ref' => $o->ref, 'label' => $o->label, 'unidad' => $unidad,
				'factor' => ($g['unidad'] !== '' ? (float) $g['factor'] : 1.0));
		}
		if ($items) {
			$grupos[] = array('titulo' => $g['titulo'], 'unidad' => $g['unidad'], 'factor' => (float) $g['factor'], 'items' => $items);
		}
	}
	return $grupos;
}

/**
 * Existencia real de los productos en un almacén.
 *
 * @param DoliDB $db
 * @param int    $wh
 * @return array<int,float>  [fk_product => reel]
 */
function sabrooskiposStockAlmacen($db, $wh)
{
	$res = array();
	$resql = $db->query("SELECT fk_product, reel FROM ".MAIN_DB_PREFIX."product_stock WHERE fk_entrepot = ".((int) $wh));
	while ($resql && ($o = $db->fetch_object($resql))) {
		$res[(int) $o->fk_product] = (float) $o->reel;
	}
	return $res;
}

/**
 * Fecha los movimientos de una operación: si es hoy quedan con la hora actual; si es un
 * día anterior, ese día a la hora indicada (para que el reporte los cuente en su turno).
 *
 * @param DoliDB $db
 * @param string $fecha  'YYYY-MM-DD'
 * @param string $hora   'HH:MM:SS' para días anteriores
 * @param string $where  condición SQL sobre llx_stock_mouvement
 * @return void
 */
function sabrooskiposFecharMovimientos($db, $fecha, $hora, $where)
{
	if ($fecha !== dol_print_date(dol_now(), '%Y-%m-%d')) {
		$db->query("UPDATE ".MAIN_DB_PREFIX."stock_mouvement SET datem = '".$db->escape($fecha.' '.$hora)."' WHERE ".$where);
	}
}

/**
 * Almacén principal (de donde sale la carga del camión). Si aún no está configurado toma
 * el que tenía MovilStock (MOVILSTOCK_ID_WAREHOUSE_MAIN) y lo guarda como propio.
 *
 * @param DoliDB $db
 * @return int  id del almacén o 0
 */
function sabrooskiposAlmacenPrincipal($db)
{
	global $conf;
	$main = getDolGlobalInt('SABROOSKIPOS_ID_WAREHOUSE_MAIN');
	if ($main <= 0 && getDolGlobalInt('MOVILSTOCK_ID_WAREHOUSE_MAIN') > 0) {
		$main = getDolGlobalInt('MOVILSTOCK_ID_WAREHOUSE_MAIN');
		dolibarr_set_const($db, 'SABROOSKIPOS_ID_WAREHOUSE_MAIN', (string) $main, 'chaine', 0, '', $conf->entity);
	}
	return $main;
}

/**
 * Traslado entre almacenes: por producto, salida del origen y entrada al destino con la
 * misma etiqueta y código de inventario. Revisa existencia antes de mover. No abre ni
 * cierra transacción.
 *
 * @param DoliDB           $db
 * @param User             $user
 * @param int              $src
 * @param int              $dst
 * @param array<int,float> $lines   [fk_product => qty] (qty > 0)
 * @param string           $label
 * @param string           $code    inventorycode de los movimientos
 * @param array            $errors  (por referencia)
 * @return int   nº de productos trasladados (>0) o <0 si error
 */
function sabrooskiposTraslado($db, $user, $src, $dst, $lines, $label, $code, &$errors)
{
	foreach ($lines as $pid => $q) {
		$p = new Product($db);
		if ($p->fetch($pid) <= 0) {
			$errors[] = 'Producto '.$pid.' no encontrado';
			return -1;
		}
		$p->load_stock('novirtual');
		$avail = isset($p->stock_warehouse[$src]->real) ? (float) $p->stock_warehouse[$src]->real : 0;
		if ($q > $avail + 0.00001) {
			$errors[] = $p->ref.': no hay suficiente en el almacén de origen (hay '.price2num($avail).', se piden '.price2num($q).').';
			return -1;
		}
		if ($p->correct_stock($user, $src, $q, 1, $label, $p->pmp, $code) <= 0
			|| $p->correct_stock($user, $dst, $q, 0, $label, $p->pmp, $code) <= 0) {
			$errors[] = $p->ref.': '.$p->error;
			return -1;
		}
	}
	return count($lines);
}

/**
 * Registra una carga de la planilla.
 *  - 'inicial' / 'reposicion': traslado Principal → móvil, etiqueta
 *    "Existencia inicial …" o "Reposición …" (reglas del reporte del turno).
 *  - 'principal': entrada directa a Principal (compras o conteo inicial de Principal).
 *
 * @param DoliDB $db
 * @param User   $user
 * @param string $tipo        'inicial' | 'reposicion' | 'principal'
 * @param int    $dst         almacén móvil (ignorado en 'principal')
 * @param array  $cantidades  [fk_product => cantidad en unidad de control (g, uds)]
 * @param string $fecha       'YYYY-MM-DD'
 * @param array  $errors      (por referencia)
 * @return int   >0 OK (nº de productos), <0 error
 */
function sabrooskiposRegistrarCarga($db, $user, $tipo, $dst, $cantidades, $fecha, &$errors)
{
	$main = sabrooskiposAlmacenPrincipal($db);
	$lines = array();
	foreach ($cantidades as $pid => $q) {
		$q = round((float) $q, 4);
		if ($pid > 0 && $q > 0) {
			$lines[(int) $pid] = $q;
		}
	}
	if (!$lines) {
		$errors[] = 'No hay cantidades para cargar.';
		return -1;
	}
	if ($main <= 0) {
		$errors[] = 'Falta el almacén principal (Setup → Sabrooski POS → Configuración).';
		return -1;
	}
	$fechatxt = dol_print_date(dol_stringtotime($fecha.' 12:00:00'), '%d/%m/%Y');

	if ($tipo == 'principal') {
		$code = 'ENTRADA-'.dol_print_date(dol_now(), '%Y%m%d%H%M%S');
		foreach ($lines as $pid => $q) {
			$p = new Product($db);
			$p->fetch($pid);
			if ($p->correct_stock($user, $main, $q, 0, 'Entrada a Principal '.$fechatxt, 0, $code) <= 0) {
				$errors[] = $p->ref.': '.$p->error;
				return -1;
			}
		}
		sabrooskiposFecharMovimientos($db, $fecha, '08:00:00', "inventorycode = '".$db->escape($code)."'");
		return count($lines);
	}

	if ($dst <= 0 || $dst == $main) {
		$errors[] = 'Elige el almacén del móvil.';
		return -1;
	}
	$code = 'CARGA-'.dol_print_date(dol_now(), '%Y%m%d%H%M%S').'-'.substr(uniqid(), -4); // único aunque haya dos cargas en el mismo segundo
	$label = ($tipo == 'inicial' ? 'Existencia inicial' : 'Reposición').' '.$fechatxt.' · '.$code;
	$n = sabrooskiposTraslado($db, $user, $main, $dst, $lines, $label, $code, $errors);
	if ($n > 0) {
		sabrooskiposFecharMovimientos($db, $fecha, ($tipo == 'inicial' ? '07:00:00' : '12:00:00'), "inventorycode = '".$db->escape($code)."'");
	}
	return $n;
}

/**
 * ¿El insumo se controla solo por conteo (no se descuenta al vender)? Siropes y toppings
 * (incluye pirulín y oreo): el cliente elige en la barra.
 *
 * @param DoliDB $db
 * @param int    $pid
 * @return bool
 */
function sabrooskiposEsDeConteo($db, $pid)
{
	static $ids = null;
	if ($ids === null) {
		$ids = array();
		$resql = $db->query("SELECT cp.fk_product FROM ".MAIN_DB_PREFIX."categorie_product cp JOIN ".MAIN_DB_PREFIX."categorie c ON c.rowid = cp.fk_categorie"
			." WHERE c.label IN ('Sirope', 'Topping')");
		while ($resql && ($o = $db->fetch_object($resql))) {
			$ids[(int) $o->fk_product] = 1;
		}
	}
	return isset($ids[(int) $pid]);
}

/**
 * Registra el conteo de cierre del móvil: deja cada insumo contado en su cantidad.
 * Siropes y toppings: la diferencia es el consumo del turno ("Consumo del turno (conteo)").
 * El resto: "Ajuste cierre (conteo)" (diferencia contra lo que ya descontaron las ventas).
 *
 * @param DoliDB $db
 * @param User   $user
 * @param int    $wh       almacén del móvil
 * @param array  $conteos  [fk_product => cantidad contada en unidad de control; '' = no contado]
 * @param string $fecha    'YYYY-MM-DD'
 * @param array  $errors   (por referencia)
 * @return int   nº de ajustes (>=0) o <0 error
 */
function sabrooskiposRegistrarCierre($db, $user, $wh, $conteos, $fecha, &$errors)
{
	if ($wh <= 0) {
		$errors[] = 'Elige el almacén del móvil.';
		return -1;
	}
	$stock = sabrooskiposStockAlmacen($db, $wh);
	$code = 'CIERRE-'.dol_print_date(dol_now(), '%Y%m%d%H%M%S');
	$n = 0;
	foreach ($conteos as $pid => $contado) {
		if ($contado === '' || $contado === null) {
			continue;
		}
		$contado = round((float) $contado, 4);
		$diff = round($contado - (isset($stock[(int) $pid]) ? $stock[(int) $pid] : 0), 4);
		if (abs($diff) < 0.0001) {
			continue;
		}
		$p = new Product($db);
		$p->fetch((int) $pid);
		if (sabrooskiposEsDeConteo($db, $pid)) {
			$label = ($diff < 0 ? 'Consumo del turno (conteo)' : 'Ajuste cierre (conteo mayor al sistema)');
		} else {
			$label = 'Ajuste cierre (conteo)';
		}
		if ($p->correct_stock($user, $wh, abs($diff), ($diff < 0 ? 1 : 0), $label, 0, $code) <= 0) {
			$errors[] = $p->ref.': '.$p->error;
			return -1;
		}
		$n++;
	}
	sabrooskiposFecharMovimientos($db, $fecha, '23:00:00', "inventorycode = '".$db->escape($code)."'");
	return $n;
}
