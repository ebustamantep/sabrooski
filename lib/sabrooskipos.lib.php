<?php
/* Copyright (C) 2026		Edgar Bustamante
 * Copyright (C) 2025       Frédéric France         <frederic.france@free.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    sabrooskipos/lib/sabrooskipos.lib.php
 * \ingroup sabrooskipos
 * \brief   Library files with common functions for SabrooskiPOS
 */

/**
 * Leer una constante de configuración del módulo por terminal, con fallback al
 * valor global (sin sufijo de terminal) para compatibilidad.
 *
 * Convención: si existe la constante <NAME><term> (ej. SABROOSKIPOS_CATEGORY_FLAVORS2)
 * se usa; si no, se usa la constante global <NAME> (sin terminal).
 *
 * @param string $name      Nombre base de la constante (ej. 'SABROOSKIPOS_CATEGORY_FLAVORS')
 * @param int    $term      Número de terminal (1, 2, ...)
 * @return string           Valor de la constante ('' si no existe)
 */
function sabrooskiposGetConst($name, $term = 0)
{
	$term = (int) $term;
	$key = $name.($term > 0 ? $term : '');

	$val = getDolGlobalString($key);
	$val = trim((string) $val);

	// Si el terminal no tiene valor propio, caemos al valor global (sin sufijo).
	if ($val === '' && $term > 0 && $name !== $key) {
		$val = trim((string) getDolGlobalString($name));
	}

	return $val;
}

/**
 * Asegura que existan los extrafields de producto del módulo (idempotente).
 *
 * @param DoliDB $db
 * @return void
 */
function sabrooskiposEnsureExtrafields($db)
{
	$extrafields = new ExtraFields($db);
	$optssel = array('options' => array('' => 'Ninguno', 'mix' => 'Mix (servidos)', 'bandejas' => 'Bandejas'));
	$cond = 'isModEnabled("sabrooskipos")';

	// Solo se crean los que faltan: addExtraField() hace ALTER TABLE, y en MySQL eso
	// confirma (commit implícito) cualquier transacción abierta.
	$existing = $extrafields->fetch_name_optionals_label('product');
	if (!is_array($existing)) {
		$existing = array();
	}
	$defs = array(
		array('max_sabores', 'Máx. sabores', 'int', 100, 3, ''),
		array('max_toppings_incluidos', 'Máx. toppings incluidos', 'int', 101, 3, ''),
		array('max_sirope', 'Máx. sirope', 'int', 102, 3, ''),
		array('grupo_sabores', 'Grupo de sabores', 'select', 103, 0, $optssel),
		array('gramos_porcion', 'Gramos por porción', 'varchar', 104, 16, ''),
	);
	foreach ($defs as $d) {
		if (!isset($existing[$d[0]])) {
			$extrafields->addExtraField($d[0], $d[1], $d[2], $d[3], $d[4], 'product', 0, 0, '', $d[5], 1, '', 0, '', '', '', 'sabrooskipos@sabrooskipos', $cond);
		}
	}
}

/**
 * Devuelve el id de la categoría de producto con ese label, o la crea bajo $parentid.
 *
 * @param DoliDB   $db
 * @param string   $label
 * @param int      $parentid  fk_parent (0 = nivel raíz)
 * @param User     $user
 * @param array    $report    Reporte (por referencia)
 * @return int  id de la categoría (0 si error)
 */
function sabrooskiposEnsureCategory($db, $label, $parentid, $user, &$report)
{
	$c = new Categorie($db);
	$res = $c->fetch(0, $label, Categorie::TYPE_PRODUCT);
	if ($res > 0) {
		return (int) $c->id;
	}

	$c = new Categorie($db);
	$c->label = $label;
	$c->type = Categorie::TYPE_PRODUCT;
	$c->fk_parent = (int) $parentid;
	$id = $c->create($user);
	if ($id > 0) {
		$report['categorias_creadas'][] = $label.' (id '.$id.')';
		return (int) $id;
	}

	$report['errores'][] = 'Categoría "'.$label.'": '.$c->error;
	return 0;
}

/**
 * Crea un producto (por ref) si no existe.
 *
 * @param DoliDB   $db
 * @param string   $ref
 * @param string   $label
 * @param float    $price_ttc
 * @param int      $catid
 * @param User     $user
 * @param array    $report
 * @param array    $opts     ['tva_tx' => float, 'extrafields' => [name => value]]
 * @return int  id del producto (0 si error)
 */
function sabrooskiposEnsureProduct($db, $ref, $label, $price_ttc, $catid, $user, &$report, $opts = array())
{
	$prod = new Product($db);
	if ($prod->fetch(0, $ref) > 0) {
		// Ya existe: refrescamos sus extrafields si se proporcionan, para que
		// re-ejecutar la carga converja (p. ej. tras corregir las definiciones).
		if (!empty($opts['extrafields'])) {
			$prod->fetch_optionals();
			foreach ($opts['extrafields'] as $k => $v) {
				$prod->array_options['options_'.$k] = $v;
			}
			$prod->insertExtraFields();
		}
		$report['productos_existentes'][] = $label.' ('.$ref.')';
		return (int) $prod->id;
	}

	$tva = isset($opts['tva_tx']) ? (float) $opts['tva_tx'] : 0;

	$prod->ref = $ref;
	$prod->label = $label;
	$prod->status = 1;
	$prod->type = 0;
	$prod->tosell = isset($opts['tosell']) ? (int) $opts['tosell'] : 1;
	$prod->price_base_type = 'TTC';
	$prod->tva_tx = $tva;

	$id = $prod->create($user);
	if ($id <= 0) {
		$report['errores'][] = 'Producto "'.$ref.'": '.$prod->error;
		return 0;
	}

	$prod->updatePrice($price_ttc, 'TTC', $user, $tva);

	if ($catid > 0) {
		$prod->setCategories(array($catid));
	}

	if (!empty($opts['extrafields'])) {
		foreach ($opts['extrafields'] as $k => $v) {
			$prod->array_options['options_'.$k] = $v;
		}
		$prod->insertExtraFields();
	}

	$report['productos_creados'][] = $label.' ('.$ref.')';
	return (int) $id;
}

/**
 * Carga (idempotente) el catálogo real de Sabrooski desde el JSON.
 *
 * Crea/asegura categorías, productos (precios TTC + extrafields) y constantes.
 * Lo que ya existe (por ref/label) no se duplica. Fuente única usada tanto por la
 * página admin/cargar_catalogo.php como por el runner CLI test/cargar_catalogo_cli.php.
 *
 * @param DoliDB $db        Conexión
 * @param User   $user      Usuario admin que realiza la carga
 * @param string $jsonfile  Ruta absoluta del JSON del catálogo
 * @param array  $report    Reporte (por referencia)
 * @return int  1 si OK, 0 si el JSON no se pudo leer/decodificar
 */
function sabrooskiposLoadCatalogo($db, $user, $jsonfile, &$report)
{
	global $conf;

	require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
	require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
	require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

	// Extrafields del módulo
	sabrooskiposEnsureExtrafields($db);

	// Leer el JSON del catálogo real
	$raw = @file_get_contents($jsonfile);
	$data = $raw ? json_decode($raw, true) : null;
	if (!is_array($data)) {
		return 0;
	}

	$rootid = getDolGlobalInt('TAKEPOS_ROOT_CATEGORY_ID');
	if ($rootid <= 0) {
		$report['notas'][] = 'TAKEPOS_ROOT_CATEGORY_ID no está configurada: las categorías de venta se crean en el nivel raíz.';
	}

	// Categorías de venta (hijas de la raíz del TakePOS)
	$catlabels = array('Servido en camión', 'Especial domo', 'Empaquetado', 'Adicional');
	$catsale = array();
	foreach ($catlabels as $catlabel) {
		$catsale[$catlabel] = sabrooskiposEnsureCategory($db, $catlabel, $rootid, $user, $report);
	}

	// Categorías de sabores (fuera de la raíz para que NO salgan como pestañas)
	$catmix = sabrooskiposEnsureCategory($db, 'Sabores mix', 0, $user, $report);
	$catbdj = sabrooskiposEnsureCategory($db, 'Sabores bandejas', 0, $user, $report);

	// Productos de sabor (listas de selección)
	if (!empty($data['sabores']['mix'])) {
		foreach ($data['sabores']['mix'] as $s) {
			sabrooskiposEnsureProduct($db, $s['codigo'], $s['nombre'], 0, $catmix, $user, $report);
		}
	}
	if (!empty($data['sabores']['bandejas'])) {
		foreach ($data['sabores']['bandejas'] as $s) {
			sabrooskiposEnsureProduct($db, $s['codigo'], $s['nombre'], 0, $catbdj, $user, $report);
		}
	}

	// Productos base
	$adicionalId = 0;
	// IVA aplicado a los precios del catálogo. Los `precio_usd` del JSON son el
	// precio final (TTC): se guardan como base TTC con este tipo de IVA (16% por defecto).
	$tva = (float) getDolGlobalString('SABROOSKIPOS_TVA_TX', '16');

	if (!empty($data['productos'])) {
		foreach ($data['productos'] as $p) {
			$categoria = isset($p['categoria']) ? (string) $p['categoria'] : '';
			$catid = (isset($catsale[$categoria]) && $catsale[$categoria] > 0) ? $catsale[$categoria] : $rootid;

			// Grupo de sabores: mix (servidos/especiales), bandejas (bandejas) o ninguno.
			$grupo = '';
			if ($categoria == 'Servido en camión' || $categoria == 'Especial domo') {
				$grupo = 'mix';
			} elseif ($categoria == 'Empaquetado' && stripos((string) $p['nombre'], 'bandeja') !== false) {
				$grupo = 'bandejas';
			}

			// Gramos de la porción principal (mix INS-001) tomados de la receta.
			$gramos = '';
			if (!empty($p['receta'])) {
				foreach ($p['receta'] as $r) {
					if (isset($r['insumo']) && $r['insumo'] == 'INS-001' && isset($r['cantidad'])) {
						$gramos = (string) $r['cantidad'];
						break;
					}
				}
			}

			$opts = array(
				'tva_tx' => $tva,
				'extrafields' => array(
					'max_sabores' => ($grupo != '' ? 1 : 0),
					'max_toppings_incluidos' => 0,
					'max_sirope' => 0,
					'grupo_sabores' => $grupo,
					'gramos_porcion' => $gramos,
				),
			);

			$pid = sabrooskiposEnsureProduct($db, $p['codigo'], $p['nombre'], (float) $p['precio_usd'], $catid, $user, $report, $opts);
			if ($p['codigo'] == 'PV-030') {
				$adicionalId = $pid;
			}
		}
	}

	// Constantes del módulo (solo se rellenan si están vacías)
	$defaults = array(
		'SABROOSKIPOS_CATEGORY_FLAVORS' => $catmix,
		'SABROOSKIPOS_CATEGORY_FLAVORS_BANDEJAS' => $catbdj,
		'SABROOSKIPOS_CATEGORY_ADICIONAL' => (isset($catsale['Adicional']) ? $catsale['Adicional'] : 0),
	);
	foreach ($defaults as $k => $v) {
		if ((int) getDolGlobalInt($k) <= 0 && $v > 0) {
			dolibarr_set_const($db, $k, (string) $v, 'chaine', 0, '', $conf->entity);
			$report['notas'][] = $k.' = '.$v;
		}
	}
	if ($adicionalId > 0 && (int) getDolGlobalInt('SABROOSKIPOS_PRODUCT_ADICIONAL') <= 0) {
		dolibarr_set_const($db, 'SABROOSKIPOS_PRODUCT_ADICIONAL', (string) $adicionalId, 'chaine', 0, '', $conf->entity);
		$report['notas'][] = 'SABROOSKIPOS_PRODUCT_ADICIONAL = '.$adicionalId;
	}
	if (!empty($catsale['Adicional']) && sabrooskiposGetConst('SABROOSKIPOS_HIDDEN_CATEGORIES', 0) === '') {
		dolibarr_set_const($db, 'SABROOSKIPOS_HIDDEN_CATEGORIES', (string) $catsale['Adicional'], 'chaine', 0, '', $conf->entity);
		$report['notas'][] = 'SABROOSKIPOS_HIDDEN_CATEGORIES = '.$catsale['Adicional'];
	}

	return 1;
}

/**
 * Exporta el catálogo (categorías de producto + productos + constantes) a un
 * array portable, usando labels/refs en vez de IDs. Lo consume
 * sabrooskiposImportCatalogo() en otra instalación.
 *
 * @param DoliDB $db
 * @return array
 */
function sabrooskiposExportCatalogo($db)
{
	global $conf;

	$entity = (int) $conf->entity;
	$typeProduct = Categorie::TYPE_PRODUCT;

	$idToLabel = array();
	$idToRef = array();

	// Categorías de producto (con label del padre)
	$categories = array();
	$sql = "SELECT c.rowid, c.label, c.fk_parent, pc.label AS parent_label";
	$sql .= " FROM ".MAIN_DB_PREFIX."categorie as c";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."categorie as pc ON pc.rowid = c.fk_parent";
	$sql .= " WHERE c.type = ".((int) $typeProduct)." AND c.entity = ".$entity;
	$sql .= " ORDER BY c.fk_parent ASC, c.label ASC";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$categories[] = array('label' => (string) $obj->label, 'parent' => (string) $obj->parent_label);
			$idToLabel[(int) $obj->rowid] = (string) $obj->label;
		}
	}

	// Productos ligados a alguna categoría de producto
	$products = array();
	$idxByRef = array();
	$sql = "SELECT DISTINCT p.rowid, p.ref, p.label, p.price_ttc, p.tva_tx, p.tosell, p.tobuy, p.stockable_product, p.description, p.cost_price";
	$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
	$sql .= " JOIN ".MAIN_DB_PREFIX."categorie_product as cp ON cp.fk_product = p.rowid";
	$sql .= " JOIN ".MAIN_DB_PREFIX."categorie as c ON c.rowid = cp.fk_categorie AND c.type = ".((int) $typeProduct);
	$sql .= " WHERE p.entity = ".$entity;
	$sql .= " ORDER BY p.ref ASC";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$ref = (string) $obj->ref;
			$idxByRef[$ref] = count($products);
			$products[] = array(
				'ref' => $ref,
				'label' => (string) $obj->label,
				'price_ttc' => (float) $obj->price_ttc,
				'tva_tx' => (float) $obj->tva_tx,
				'tosell' => (int) $obj->tosell,
				'tobuy' => (int) $obj->tobuy,
				'stockable_product' => (int) $obj->stockable_product,
				'description' => (string) $obj->description,
				'cost_price' => ($obj->cost_price === null ? null : (float) $obj->cost_price),
				'categories' => array(),
				'extrafields' => array(),
			);
			$idToRef[(int) $obj->rowid] = $ref;
		}
	}

	// Categorías por producto (labels)
	$sql = "SELECT cp.fk_product, c.label FROM ".MAIN_DB_PREFIX."categorie_product as cp";
	$sql .= " JOIN ".MAIN_DB_PREFIX."categorie as c ON c.rowid = cp.fk_categorie";
	$sql .= " WHERE c.type = ".((int) $typeProduct)." AND c.entity = ".$entity;
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$pid = (int) $obj->fk_product;
			if (isset($idToRef[$pid]) && isset($idxByRef[$idToRef[$pid]])) {
				$products[$idxByRef[$idToRef[$pid]]]['categories'][] = (string) $obj->label;
			}
		}
	}

	// Extrafields de producto: definiciones (para recrearlas en destino) + valores
	$efdefs = array();
	$efnames = array();
	$sql = "SELECT name, label, type, size, pos, param, fielddefault, alwayseditable, perms, list, help, enabled, fieldrequired, fieldunique, totalizable, printable";
	$sql .= " FROM ".MAIN_DB_PREFIX."extrafields";
	$sql .= " WHERE elementtype = 'product' AND type <> 'separate' AND (entity = ".$entity." OR entity = 0)";
	$sql .= " ORDER BY pos ASC, name ASC";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$nm = (string) $obj->name;
			if (!preg_match('/^\w[a-zA-Z0-9_]*$/', $nm)) {
				continue; // nombre no válido como columna
			}
			$efnames[] = $nm;
			$param = $obj->param;
			if (is_string($param) && $param !== '') {
				$tmp = @unserialize($param);
				if ($tmp !== false || $param === 'b:0;') {
					$param = $tmp;
				}
			}
			$efdefs[] = array(
				'name' => $nm,
				'label' => (string) $obj->label,
				'type' => (string) $obj->type,
				'size' => (string) $obj->size,
				'pos' => (int) $obj->pos,
				'param' => $param,
				'default_value' => (string) $obj->fielddefault,
				'alwayseditable' => (int) $obj->alwayseditable,
				'perms' => (string) $obj->perms,
				'list' => (string) $obj->list,
				'help' => (string) $obj->help,
				'enabled' => (string) $obj->enabled,
				'required' => (int) $obj->fieldrequired,
				'unique' => (int) $obj->fieldunique,
				'totalizable' => (int) $obj->totalizable,
				'printable' => (int) $obj->printable,
			);
		}
	}

	// Valores de esos extrafields por producto
	if (!empty($efnames)) {
		$sql = "SELECT fk_object, ".implode(', ', $efnames)." FROM ".MAIN_DB_PREFIX."product_extrafields";
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$pid = (int) $obj->fk_object;
				if (isset($idToRef[$pid]) && isset($idxByRef[$idToRef[$pid]])) {
					$ef = array();
					foreach ($efnames as $n) {
						if (isset($obj->$n) && $obj->$n !== null && $obj->$n !== '') {
							$ef[$n] = (string) $obj->$n;
						}
					}
					$products[$idxByRef[$idToRef[$pid]]]['extrafields'] = $ef;
				}
			}
		}
	}

	// Constantes del módulo (por label / ref)
	$constants_by_category_label = array();
	foreach (array('SABROOSKIPOS_CATEGORY_FLAVORS', 'SABROOSKIPOS_CATEGORY_FLAVORS_BANDEJAS', 'SABROOSKIPOS_CATEGORY_TOPPINGS', 'SABROOSKIPOS_CATEGORY_SYRUPS', 'SABROOSKIPOS_CATEGORY_ADICIONAL') as $const) {
		$cid = (int) getDolGlobalInt($const);
		if ($cid > 0 && isset($idToLabel[$cid])) {
			$constants_by_category_label[$const] = $idToLabel[$cid];
		}
	}
	$constants_by_product_ref = array();
	$apid = (int) getDolGlobalInt('SABROOSKIPOS_PRODUCT_ADICIONAL');
	if ($apid > 0 && isset($idToRef[$apid])) {
		$constants_by_product_ref['SABROOSKIPOS_PRODUCT_ADICIONAL'] = $idToRef[$apid];
	}
	$hidden_category_labels = array();
	$hid = getDolGlobalString('SABROOSKIPOS_HIDDEN_CATEGORIES');
	if ($hid !== '') {
		foreach (explode(',', $hid) as $cid) {
			$cid = (int) trim($cid);
			if ($cid > 0 && isset($idToLabel[$cid])) {
				$hidden_category_labels[] = $idToLabel[$cid];
			}
		}
	}
	$rootid = (int) getDolGlobalInt('TAKEPOS_ROOT_CATEGORY_ID');
	$root_category_label = ($rootid > 0 && isset($idToLabel[$rootid])) ? $idToLabel[$rootid] : '';

	// Productos retirados del catálogo: los del prototipo y los reemplazados por los
	// productos por sabor (ver test/adaptar_catalogo_planilla_cli.php), más los que
	// quedaron fuera de venta y sin categoría. PV-030 Adicional lo reemplazan los genéricos
	// Topping (PV-031) y Sirope (PV-032). Ninguno de estos viaja como producto.
	$retired = array('PV-020', 'PV-022', 'SIR-CARA', 'SIR-VAIN', 'BARQ-110', 'BARQ-160', 'CEST-120', 'SUND-170',
		'SAB-CHOC', 'SAB-VAIN', 'SAB-FRES', 'SAB-RONP', 'SAB-COCO', 'SAB-PARC', 'SAB-CAFE', 'SAB-CHIC', 'PV-030');
	$sql = "SELECT p.ref FROM ".MAIN_DB_PREFIX."product as p";
	$sql .= " WHERE p.entity = ".$entity." AND p.tosell = 0 AND p.tobuy = 0";
	$sql .= " AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."categorie_product as cp WHERE cp.fk_product = p.rowid)";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			if (!isset($idxByRef[$obj->ref]) && !in_array($obj->ref, $retired)) {
				$retired[] = (string) $obj->ref;
			}
		}
	}
	$products = array_values(array_filter($products, function ($p) use ($retired) {
		return !in_array($p['ref'], $retired);
	}));

	// Kits (recetas de los servidos y reparto de mix de los sabores), por ref.
	$exportados = array();
	foreach ($products as $p) {
		$exportados[$p['ref']] = 1;
	}
	$kits = array();
	$resql = $db->query("SELECT pp.ref AS padre, pf.ref AS hijo, a.qty, a.incdec FROM ".MAIN_DB_PREFIX."product_association a"
		." JOIN ".MAIN_DB_PREFIX."product pp ON pp.rowid = a.fk_product_pere JOIN ".MAIN_DB_PREFIX."product pf ON pf.rowid = a.fk_product_fils"
		." ORDER BY pp.ref, a.rang, pf.ref");
	while ($resql && ($obj = $db->fetch_object($resql))) {
		if (isset($exportados[$obj->padre]) && isset($exportados[$obj->hijo])) {
			$kits[] = array('padre' => (string) $obj->padre, 'hijo' => (string) $obj->hijo, 'qty' => (float) $obj->qty, 'incdec' => (int) $obj->incdec);
		}
	}

	return array(
		'generated_at' => dol_print_date(dol_now(), 'dayhourrfc'),
		'retired_refs' => $retired,
		'root_category_label' => $root_category_label,
		'categories' => $categories,
		'products' => $products,
		'constants_by_category_label' => $constants_by_category_label,
		'constants_by_product_ref' => $constants_by_product_ref,
		'hidden_category_labels' => $hidden_category_labels,
		'extrafield_definitions' => $efdefs,
		'kits' => $kits,
		'constants_plain' => array_filter(array(
			'SABROOSKIPOS_TVA_TX' => getDolGlobalString('SABROOSKIPOS_TVA_TX', '16'),
			'PRODUIT_SOUSPRODUITS' => getDolGlobalString('PRODUIT_SOUSPRODUITS'),
			'SABROOSKIPOS_COSTO_G_MIX' => getDolGlobalString('SABROOSKIPOS_COSTO_G_MIX'),
			'SABROOSKIPOS_COSTO_G_SIROPE' => getDolGlobalString('SABROOSKIPOS_COSTO_G_SIROPE'),
			'SABROOSKIPOS_COSTO_G_TOPPING' => getDolGlobalString('SABROOSKIPOS_COSTO_G_TOPPING'),
			'SABROOSKIPOS_COSTO_PIRULIN_OREO' => getDolGlobalString('SABROOSKIPOS_COSTO_PIRULIN_OREO'),
		), function ($v) {
			return $v !== '';
		}),
	);
}

/**
 * Crea los extrafields que necesita el catálogo importado: los del módulo, los de recetas
 * (incluye el de sabores de la línea de factura) y las definiciones exportadas que falten.
 *
 * Llamar ANTES de abrir la transacción de la importación: addExtraField() hace ALTER
 * TABLE, que en MySQL confirma (commit implícito) la transacción abierta, y una
 * simulación terminaría guardando todo lo que viene después.
 *
 * @param DoliDB $db
 * @param array  $data    Catálogo exportado
 * @param array  $report  Reporte (por referencia)
 * @return void
 */
function sabrooskiposImportExtrafields($db, $data, &$report)
{
	require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

	// 1) Extrafields de producto del módulo (los conocidos) y los de recetas (incluye el
	// de sabores de la línea de factura).
	sabrooskiposEnsureExtrafields($db);
	require_once __DIR__.'/sabrooskipos_recetas.lib.php';
	sabrooskiposRecetasEnsureExtrafields($db);

	// 1b) Definiciones de extrafields exportadas: crear las que falten
	if (!empty($data['extrafield_definitions']) && is_array($data['extrafield_definitions'])) {
		$ef = new ExtraFields($db);
		$existing = $ef->fetch_name_optionals_label('product');
		if (!is_array($existing)) {
			$existing = array();
		}
		foreach ($data['extrafield_definitions'] as $d) {
			$nm = isset($d['name']) ? (string) $d['name'] : '';
			if ($nm === '' || isset($existing[$nm])) {
				continue; // ya existe (o inválido)
			}
			$res = $ef->addExtraField(
				$nm,
				isset($d['label']) ? $d['label'] : $nm,
				isset($d['type']) ? $d['type'] : 'varchar',
				isset($d['pos']) ? (int) $d['pos'] : 0,
				isset($d['size']) ? $d['size'] : '',
				'product',
				isset($d['unique']) ? (int) $d['unique'] : 0,
				isset($d['required']) ? (int) $d['required'] : 0,
				isset($d['default_value']) ? $d['default_value'] : '',
				isset($d['param']) ? $d['param'] : '',
				isset($d['alwayseditable']) ? (int) $d['alwayseditable'] : 0,
				isset($d['perms']) ? $d['perms'] : '',
				isset($d['list']) ? $d['list'] : '-1',
				isset($d['help']) ? $d['help'] : '',
				'', // computed
				'', // entity (debe ir vacío para no romper el INSERT)
				'sabrooskipos@sabrooskipos', // langfile
				isset($d['enabled']) ? $d['enabled'] : '1',
				isset($d['totalizable']) ? (int) $d['totalizable'] : 0,
				isset($d['printable']) ? (int) $d['printable'] : 0
			);
			if ($res > 0) {
				$existing[$nm] = isset($d['label']) ? $d['label'] : $nm;
				$report['notas'][] = 'extrafield creado: '.$nm;
			} else {
				$report['errores'][] = 'extrafield "'.$nm.'" no creado ('.$ef->errno.')';
			}
		}
	}
}

/**
 * Importa un catálogo exportado (categorías + productos + constantes) resolviendo
 * los IDs por LABEL (categorías) y REF (productos), para poder pasar datos entre
 * instalaciones con IDs distintos. Idempotente.
 *
 * Estructura esperada de $data (ver admin/catalogo_export.php):
 *   categories: [ {label, parent}, ... ]
 *   products:   [ {ref, label, price_ttc, tosell, categories:[label,...], extrafields:{...}}, ... ]
 *   root_category_label: "Productos de punto de venta (POS)"
 *   constants_by_category_label: { CONST => "label", ... }
 *   constants_by_product_ref:    { CONST => "ref", ... }
 *   hidden_category_labels: ["Adicional", ...]
 *   constants_plain: { CONST => "valor", ... }
 *
 * @param DoliDB $db
 * @param User   $user
 * @param array  $data    Datos decodificados del JSON
 * @param array  $report  Reporte (por referencia)
 * @return int 1 si OK, 0 si los datos no son válidos
 */
function sabrooskiposImportCatalogo($db, $user, $data, &$report)
{
	global $conf;

	require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
	require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
	require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

	if (!is_array($data) || (empty($data['categories']) && empty($data['products']))) {
		return 0;
	}

	// 1) Extrafields (no-op si ya existen; quien llama los crea antes de la transacción)
	sabrooskiposImportExtrafields($db, $data, $report);

	// 2) Categorías (multi-pase por jerarquía: primero los padres)
	$cats = (isset($data['categories']) && is_array($data['categories'])) ? $data['categories'] : array();
	$idbyLabel = array();
	$pending = $cats;
	$guard = 0;
	while (!empty($pending) && $guard < 100) {
		$guard++;
		$next = array();
		foreach ($pending as $c) {
			$label = isset($c['label']) ? (string) $c['label'] : '';
			if ($label === '') {
				continue;
			}
			$parentLabel = isset($c['parent']) ? (string) $c['parent'] : '';
			if ($parentLabel === '') {
				$parentid = 0;
			} elseif (isset($idbyLabel[$parentLabel])) {
				$parentid = $idbyLabel[$parentLabel];
			} else {
				$next[] = $c; // aún no existe el padre: reintentar luego
				continue;
			}
			$id = sabrooskiposEnsureCategory($db, $label, $parentid, $user, $report);
			if ($id > 0) {
				$idbyLabel[$label] = $id;
			}
		}
		if (count($next) === count($pending)) {
			break; // sin progreso: padres inexistentes
		}
		$pending = $next;
	}
	foreach ($pending as $c) {
		$report['errores'][] = 'Categoría no creada "'.(isset($c['label']) ? $c['label'] : '?').'" (padre "'.(isset($c['parent']) ? $c['parent'] : '').'")';
	}

	// 3) Productos
	$tva = (float) getDolGlobalString('SABROOSKIPOS_TVA_TX', '16');
	$prodIdByRef = array();
	$products = (isset($data['products']) && is_array($data['products'])) ? $data['products'] : array();
	foreach ($products as $p) {
		$ref = isset($p['ref']) ? trim((string) $p['ref']) : '';
		if ($ref === '') {
			continue;
		}
		$label = isset($p['label']) ? (string) $p['label'] : $ref;
		$price = isset($p['price_ttc']) ? (float) $p['price_ttc'] : 0;

		$catids = array();
		if (!empty($p['categories']) && is_array($p['categories'])) {
			foreach ($p['categories'] as $clabel) {
				if (isset($idbyLabel[$clabel])) {
					$catids[] = $idbyLabel[$clabel];
				}
			}
		}

		$ptva = (isset($p['tva_tx']) ? (float) $p['tva_tx'] : $tva);
		$opts = array(
			'tva_tx' => $ptva,
			'tosell' => (isset($p['tosell']) ? (int) $p['tosell'] : 1),
			'extrafields' => (isset($p['extrafields']) && is_array($p['extrafields'])) ? $p['extrafields'] : array(),
		);

		$pid = sabrooskiposEnsureProduct($db, $ref, $label, $price, (count($catids) ? $catids[0] : 0), $user, $report, $opts);
		if ($pid > 0) {
			$prodIdByRef[$ref] = $pid;
			// Converger el producto existente con el origen: etiqueta, estado, precio,
			// descripción, manejo de stock y categorías exactas.
			$prod = new Product($db);
			if ($prod->fetch($pid) > 0) {
				$tosell = (isset($p['tosell']) ? (int) $p['tosell'] : 1);
				$tobuy = (isset($p['tobuy']) ? (int) $p['tobuy'] : (int) $prod->status_buy);
				$desc = (isset($p['description']) ? (string) $p['description'] : $prod->description);
				if ($prod->label !== $label || (int) $prod->status != $tosell || (int) $prod->status_buy != $tobuy || $prod->description !== $desc) {
					$prod->label = $label;
					$prod->status = $tosell;
					$prod->status_buy = $tobuy;
					$prod->description = $desc;
					$prod->update($prod->id, $user);
					$report['notas'][] = 'actualizado: '.$ref;
				}
				if (abs((float) $prod->price_ttc - $price) > 0.0001 || abs((float) $prod->tva_tx - $ptva) > 0.0001) {
					$prod->updatePrice($price, 'TTC', $user, $ptva);
					$report['notas'][] = 'precio: '.$ref.' = '.$price;
				}
				if (isset($p['stockable_product'])) {
					$db->query("UPDATE ".MAIN_DB_PREFIX."product SET stockable_product = ".((int) $p['stockable_product'])." WHERE rowid = ".((int) $pid));
				}
				if (isset($p['cost_price']) && $p['cost_price'] !== null && abs((float) $prod->cost_price - (float) $p['cost_price']) > 0.0000001) {
					$db->query("UPDATE ".MAIN_DB_PREFIX."product SET cost_price = ".((float) $p['cost_price'])." WHERE rowid = ".((int) $pid));
					$report['notas'][] = 'costo: '.$ref.' = '.$p['cost_price'];
				}
				$prod->setCategories($catids);
			}
		}
	}

	// 3b) Productos retirados en el origen
	if (!empty($data['retired_refs']) && is_array($data['retired_refs'])) {
		foreach ($data['retired_refs'] as $ref) {
			sabrooskiposRetireProduct($db, $user, (string) $ref, $report);
		}
	}

	// 3c) Kits (recetas): cada padre queda exactamente con los hijos del origen.
	if (isset($data['kits']) && is_array($data['kits'])) {
		$porPadre = array();
		foreach ($data['kits'] as $k) {
			if (isset($prodIdByRef[$k['padre']]) && isset($prodIdByRef[$k['hijo']])) {
				$porPadre[$k['padre']]['incdec'] = (int) $k['incdec'];
				$porPadre[$k['padre']]['hijos'][$prodIdByRef[$k['hijo']]] = (float) $k['qty'];
			} else {
				$report['errores'][] = 'Kit '.$k['padre'].' → '.$k['hijo'].': producto no encontrado';
			}
		}
		foreach ($porPadre as $pref => $k) {
			$prod = new Product($db);
			$prod->fetch($prodIdByRef[$pref]);
			if (sabrooskiposAplicarKit($db, $user, $prod, $k['hijos'], $k['incdec'], $report)) {
				$report['notas'][] = 'kit: '.$pref.' ('.count($k['hijos']).' insumos)';
			}
		}
	}

	// 4) Constantes (por label / ref / valor)
	if (!empty($data['root_category_label']) && isset($idbyLabel[$data['root_category_label']])) {
		dolibarr_set_const($db, 'TAKEPOS_ROOT_CATEGORY_ID', (string) $idbyLabel[$data['root_category_label']], 'chaine', 0, '', $conf->entity);
		$report['notas'][] = 'TAKEPOS_ROOT_CATEGORY_ID = '.$idbyLabel[$data['root_category_label']];
	}
	if (!empty($data['constants_by_category_label']) && is_array($data['constants_by_category_label'])) {
		foreach ($data['constants_by_category_label'] as $const => $clabel) {
			if (isset($idbyLabel[$clabel])) {
				dolibarr_set_const($db, $const, (string) $idbyLabel[$clabel], 'chaine', 0, '', $conf->entity);
				$report['notas'][] = $const.' = '.$idbyLabel[$clabel].' ('.$clabel.')';
			} else {
				$report['errores'][] = $const.': categoría "'.$clabel.'" no encontrada';
			}
		}
	}
	if (!empty($data['constants_by_product_ref']) && is_array($data['constants_by_product_ref'])) {
		foreach ($data['constants_by_product_ref'] as $const => $pref) {
			if (isset($prodIdByRef[$pref])) {
				dolibarr_set_const($db, $const, (string) $prodIdByRef[$pref], 'chaine', 0, '', $conf->entity);
				$report['notas'][] = $const.' = '.$prodIdByRef[$pref].' ('.$pref.')';
			} else {
				$report['errores'][] = $const.': producto "'.$pref.'" no encontrado';
			}
		}
	}
	if (!empty($data['hidden_category_labels']) && is_array($data['hidden_category_labels'])) {
		$ids = array();
		foreach ($data['hidden_category_labels'] as $clabel) {
			if (isset($idbyLabel[$clabel])) {
				$ids[] = $idbyLabel[$clabel];
			}
		}
		if ($ids) {
			dolibarr_set_const($db, 'SABROOSKIPOS_HIDDEN_CATEGORIES', implode(',', $ids), 'chaine', 0, '', $conf->entity);
			$report['notas'][] = 'SABROOSKIPOS_HIDDEN_CATEGORIES = '.implode(',', $ids);
		}
	}
	if (!empty($data['constants_plain']) && is_array($data['constants_plain'])) {
		foreach ($data['constants_plain'] as $const => $val) {
			dolibarr_set_const($db, $const, (string) $val, 'chaine', 0, '', $conf->entity);
			$report['notas'][] = $const.' = '.$val;
		}
	}

	return 1;
}

/**
 * Retira un producto: lo borra si no se usa en documentos; si se usa, lo deja fuera
 * de venta/compra y sin categorías.
 *
 * @param DoliDB $db
 * @param User   $user
 * @param string $ref
 * @param array  $report  Reporte (por referencia)
 * @return void
 */
function sabrooskiposRetireProduct($db, $user, $ref, &$report)
{
	$p = new Product($db);
	if ($p->fetch(0, $ref) <= 0) {
		return;
	}
	if (!$p->isObjectUsed($p->id)) {
		$db->query("DELETE FROM ".MAIN_DB_PREFIX."product_stock WHERE fk_product = ".((int) $p->id));
		$db->query("DELETE FROM ".MAIN_DB_PREFIX."stock_mouvement WHERE fk_product = ".((int) $p->id));
		if ($p->delete($user) > 0) {
			$report['notas'][] = 'borrado: '.$ref;
			return;
		}
	}
	if ($p->status || $p->status_buy) {
		$p->status = 0;
		$p->status_buy = 0;
		$p->update($p->id, $user);
		$report['notas'][] = 'desactivado (usado en documentos): '.$ref;
	}
	$p->setCategories(array());
}

/**
 * Prepare admin pages header
 *
 * @return array<array{string,string,string}>
 */
function sabrooskiposAdminPrepareHead()
{
	global $langs, $conf;

	// global $db;
	// $extrafields = new ExtraFields($db);
	// $extrafields->fetch_name_optionals_label('myobject');

	$langs->load("sabrooskipos@sabrooskipos");

	$h = 0;
	$head = array();

	$head[$h][0] = dolBuildUrl(dol_buildpath("/sabrooskipos/admin/setup.php", 1));
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dolBuildUrl(dol_buildpath("/sabrooskipos/admin/cargar_catalogo.php", 1));
	$head[$h][1] = $langs->trans("SabrooskiPOSCargarCatalogo");
	$head[$h][2] = 'catalogo';
	$h++;

	// TEMPORAL: migración local -> producción (catálogo + config + turnos).
	$head[$h][0] = dolBuildUrl(dol_buildpath("/sabrooskipos/admin/migracion_import.php", 1));
	$head[$h][1] = 'Migración';
	$head[$h][2] = 'migracion';
	$h++;

	// Pestañas por terminal (como el TakePOS nativo). Cada punto de venta
	// (Terminal 1, 2...) tiene su propia configuración de categorías.
	$numterminals = max(1, getDolGlobalInt('TAKEPOS_NUM_TERMINALS', 1));
	for ($i = 1; $i <= $numterminals; $i++) {
		$head[$h][0] = dolBuildUrl(dol_buildpath("/sabrooskipos/admin/setup.php", 1)).'?terminal='.$i;
		$head[$h][1] = getDolGlobalString('TAKEPOS_TERMINAL_NAME_'.$i, $langs->trans("TerminalName", $i));
		$head[$h][2] = 'terminal'.$i;
		$h++;
	}

	/*
	$head[$h][0] = dolBuildUrl(dol_buildpath("/sabrooskipos/admin/myobject_extrafields.php", 1));
	$head[$h][1] = $langs->trans("ExtraFields");
	$nbExtrafields = (isset($extrafields->attributes['myobject']['label']) && is_countable($extrafields->attributes['myobject']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafields';
	$h++;

	$head[$h][0] = dolBuildUrl(dol_buildpath("/sabrooskipos/admin/myobjectline_extrafields.php", 1));
	$head[$h][1] = $langs->trans("ExtraFieldsLines");
	$nbExtrafields = (isset($extrafields->attributes['myobjectline']['label']) && is_countable($extrafields->attributes['myobjectline']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafieldsline';
	$h++;
	*/

	$head[$h][0] = dolBuildUrl(dol_buildpath("/sabrooskipos/admin/about.php", 1));
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@sabrooskipos:/sabrooskipos/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@sabrooskipos:/sabrooskipos/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, null, $head, $h, 'sabrooskipos@sabrooskipos');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'sabrooskipos@sabrooskipos', 'remove');

	return $head;
}

/**
 * Detalle de una línea de venta para el recibo, a partir de su descripción.
 *
 * El popup anota "(Chocolate, Fresa) 1 Topping 1 Sirope" en el producto y
 * "para Barquilla" en las líneas de topping/sirope; se devuelve tal cual. Las líneas
 * viejas ("Sabor: Chocolate · 82 g") se pasan al mismo formato: "(Chocolate)", sin los
 * gramos. Si la descripción es la del producto (la que pone TakePOS por defecto), no
 * hay detalle.
 *
 * @param string $desc        Descripción de la línea
 * @param string $productDesc Descripción del producto
 * @return string             Detalle en texto plano ('' si no hay)
 */
function sabrooskiposDetalleRecibo($desc, $productDesc = '')
{
	$desc = trim(dol_string_nohtmltag($desc));
	if ($desc === '' || $desc === trim(dol_string_nohtmltag($productDesc))) {
		return '';
	}

	$partes = array();
	foreach (explode('·', $desc) as $parte) {
		$parte = trim($parte);
		if ($parte === '' || preg_match('/^\d+([.,]\d+)?\s*g$/i', $parte)) {
			continue; // gramos por porción: informativo, no va en el recibo
		}
		if (preg_match('/^Sabor(es)?\s*:\s*(.+)$/iu', $parte, $m)) {
			$parte = '('.trim($m[2]).')';
		}
		$partes[] = $parte;
	}

	return implode(' ', $partes);
}
