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

	$extrafields->addExtraField('max_sabores', 'Máx. sabores', 'int', 100, 3, 'product', 0, 0, '', '', 1, '', 0, '', '', '', 'sabrooskipos@sabrooskipos', $cond);
	$extrafields->addExtraField('max_toppings_incluidos', 'Máx. toppings incluidos', 'int', 101, 3, 'product', 0, 0, '', '', 1, '', 0, '', '', '', 'sabrooskipos@sabrooskipos', $cond);
	$extrafields->addExtraField('max_sirope', 'Máx. sirope', 'int', 102, 3, 'product', 0, 0, '', '', 1, '', 0, '', '', '', 'sabrooskipos@sabrooskipos', $cond);
	$extrafields->addExtraField('grupo_sabores', 'Grupo de sabores', 'select', 103, 0, 'product', 0, 0, '', $optssel, 1, '', 0, '', '', '', 'sabrooskipos@sabrooskipos', $cond);
	$extrafields->addExtraField('gramos_porcion', 'Gramos por porción', 'varchar', 104, 16, 'product', 0, 0, '', '', 1, '', 0, '', '', '', 'sabrooskipos@sabrooskipos', $cond);
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
	$prod->tosell = 1;
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
