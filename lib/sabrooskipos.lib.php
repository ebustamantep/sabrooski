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
