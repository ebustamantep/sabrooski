<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/carga/turno.php
 * \ingroup sabrooskipos
 * \brief   Cambio de turno y cierre del día (v1.16). Un camión, varios turnos al día; el
 *          turno es la sesión de caja de POSPlus. Pasos, según el estado del turno actual:
 *            1. Caja abierta → cerrarla con el Reporte X en POSPlus (no se cuenta con la caja abierta).
 *            2. Caja cerrada sin conteo → conteo completo (las diferencias son de ese turno).
 *            3. Contado → diferencias del turno y, o bien reponer desde Principal lo que salió
 *               (sigue otro turno), o bien devolver todo a Principal (cierre del día).
 *            4. Listo → abrir la caja del turno siguiente en POSPlus.
 */

require __DIR__.'/main_carga.inc.php';

if (!$user->admin && !$user->hasRight('sabrooskipos', 'carga', 'write')) {
	accessforbidden();
}

$moviles = sabrooskiposMoviles();
$wh = GETPOSTINT('wh');
if (!isset($moviles[$wh])) {
	$wh = (int) key($moviles);
}
$term = sabrooskiposTerminalDeAlmacen($wh);
$main = sabrooskiposAlmacenPrincipal($db);
$data = sabrooskiposRecetasLeer();
$grupos = $data ? sabrooskiposCargaGrupos($db, $data) : array();
$turno = ($term !== '' ? sabrooskiposTurnoActual($db, $term) : null);
if ($turno && !sabrooskiposModoTurnos($db, $turno['fecha'])) {
	$turno = null;
}
$action = GETPOST('action', 'aZ09');
$self = $_SERVER['PHP_SELF'].'?wh='.$wh;
$keep = array();

// ---- Acciones (siempre sobre el turno actual, ya cerrado) ----
if ($turno && !$turno['abierta'] && in_array($action, array('contar', 'reponer', 'devolver'))) {
	if (GETPOSTINT('sesion') != $turno['id']) {
		setEventMessages('El turno cambió mientras tanto (¿se abrió otra caja?). Revisa y vuelve a intentar.', null, 'warnings');
		header('Location: '.$self);
		exit;
	}
	$errors = array();
	$ok = false;
	$msg = '';
	if ($action == 'contar') {
		$cont = (array) GETPOST('conteo', 'array');
		$conteos = sabrooskiposLeerConteos($grupos, $cont, $errors);
		if (!$errors && !$conteos) {
			$errors[] = 'No escribiste ningún conteo.';
		}
		if (!$errors) {
			$db->begin();
			$r = sabrooskiposRegistrarCierre($db, $user, $wh, $conteos, $turno['fecha'], $errors, $turno);
			$ok = ($r >= 0);
			$msg = 'Conteo del '.$turno['corto'].' registrado: '.count($conteos).' productos contados, '.max(0, $r).' con diferencia.';
		}
		$keep = $cont;
	} elseif ($action == 'reponer') {
		$cant = (array) GETPOST('qty', 'array');
		$cantidades = array();
		foreach ($grupos as $g) {
			foreach ($g['items'] as $it) {
				if (isset($cant[$it['id']])) {
					$q = sabrooskiposCargaValidar($it, $cant[$it['id']], true, $errors);
					if ($q !== null && $q > 0) {
						$cantidades[$it['id']] = $q * $it['factor'];
					}
				}
			}
		}
		if (!$errors) {
			$db->begin();
			$r = sabrooskiposRegistrarCarga($db, $user, 'reposicion', $wh, $cantidades, dol_print_date(dol_now(), '%Y-%m-%d'), $errors);
			$ok = ($r > 0);
			$msg = 'Reposición registrada: '.max(0, $r).' productos subidos al camión. Ya puede abrirse la caja del turno siguiente.';
		}
		$keep = $cant;
	} elseif ($action == 'devolver') {
		$db->begin();
		$r = sabrooskiposDevolverPrincipal($db, $user, $wh, $turno, $errors);
		$ok = ($r >= 0);
		$msg = 'Devueltos a Principal '.max(0, $r).' productos. El camión quedó en 0.';
	}
	if ($ok) {
		$db->commit();
		setEventMessages($msg, null, 'mesgs');
		header('Location: '.$self);
		exit;
	}
	if ($db->transaction_opened) {
		$db->rollback();
	}
	setEventMessages('No se registró.', $errors, 'errors');
}

$stMovil = sabrooskiposStockAlmacen($db, $wh);
$stMain = sabrooskiposStockAlmacen($db, $main);

llxHeader(sabrooskiposCargaCss(), 'Cambio de turno', '', '', 0, 0, '', '', '', 'mod-sabrooskipos page-turno');
print load_fiche_titre('Cambio de turno y cierre del día', '', 'fa-exchange-alt');

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" class="sbr-filter">';
print 'Móvil <select name="wh" class="flat" onchange="this.form.submit()">';
foreach ($moviles as $id => $l) {
	print '<option value="'.$id.'"'.($id == $wh ? ' selected' : '').'>'.dol_escape_htmltag($l).'</option>';
}
print '</select></form>';

if (!sabrooskiposPosplusActivo($db)) {
	print '<div class="error">El cambio de turno usa las sesiones de caja de POSPlus, y POSPlus no está activo. Usa Cierre (conteo) y Carga del camión.</div>';
	llxFooter();
	$db->close();
	exit;
}
if ($main <= 0 || !$data) {
	print '<div class="error">Falta el almacén principal (Setup → Sabrooski POS → Ajustes) o el archivo de recetas.</div>';
	llxFooter();
	$db->close();
	exit;
}
if (!$turno) {
	print '<div class="info">Este móvil aún no tiene turnos: abre la caja en el TakePOS (POSPlus → Apertura de caja). '
		.'Antes, carga el camión en <a href="'.dol_buildpath('/sabrooskipos/carga/index.php', 1).'?tipo=inicial&wh='.$wh.'">Carga del camión → Existencia inicial</a>.</div>';
	llxFooter();
	$db->close();
	exit;
}

// Estado y pasos
$fechaTxt = dol_print_date(dol_stringtotime($turno['fecha'].' 12:00:00'), '%d/%m/%Y');
if ($turno['abierta']) {
	$paso = 1;
} elseif ($turno['conteo'] === '') {
	$paso = 2;
} elseif ($turno['devol'] === '') {
	$paso = 3;
} else {
	$paso = 4;
}
$esZ = ((int) $turno['status'] == 2);
$nombres = array(1 => 'Cerrar caja (X)', 2 => 'Contar el camión', 3 => 'Diferencias y reponer / devolver', 4 => 'Abrir el turno siguiente');
print '<div class="sbr-pasos">';
foreach ($nombres as $k => $n) {
	print '<span class="sbr-paso'.($k < $paso ? ' hecho' : ($k == $paso ? ' actual' : '')).'">'.$k.'. '.$n.'</span>';
}
print '</div>';
print '<div class="opacitymedium marginbottom">'.dol_escape_htmltag($moviles[$wh]).' · <b>'.dol_escape_htmltag($turno['corto']).'</b> del '.$fechaTxt
	.' · caja '.($turno['abierta'] ? 'abierta desde las '.dol_escape_htmltag(substr($turno['date_open'], 11, 5)) : 'cerrada a las '.dol_escape_htmltag(substr($turno['date_close'], 11, 5)).($esZ ? ' (cierre Z del día)' : ' (cierre X)'))
	.' · <a href="'.dol_buildpath('/sabrooskipos/reportes/index.php', 1).'?reporte=insumos&fecha='.urlencode($turno['fecha']).'&terminal='.urlencode($turno['terminal']).'&sesion='.$turno['id'].'">ver reportes del turno</a></div>';

$hidden = '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="wh" value="'.$wh.'"><input type="hidden" name="sesion" value="'.$turno['id'].'">';

if ($paso == 1) {
	// ---- 1. Caja abierta ----
	print '<div class="info"><b>Primero cierra la caja del '.dol_escape_htmltag($turno['corto']).'</b> con el <b>Reporte X</b> de POSPlus (en el TakePOS, botón de caja). '
		.'Con la caja cerrada ya no se vende, y el conteo y las diferencias quedan para este turno. Al final de la noche, el cierre es el <b>Reporte Z</b>.</div>';
	print '<div class="center"><a class="button" href="'.$self.'">Ya cerré la caja: continuar</a></div>';
} elseif ($paso == 2) {
	// ---- 2. Conteo ----
	print '<div class="info"><b>Cuenta todo lo que queda en el camión</b> y escríbelo en <b>Conteo</b> (el mix en gramos, sin punto ni coma: 9,4 kg = 9400). '
		.'Topping y sirope: pesa todo junto. Lo que dejes vacío se toma como dice el sistema. Las diferencias quedan a cargo del '.dol_escape_htmltag($turno['corto']).'.</div>';
	print '<form method="POST" action="'.$self.'">'.$hidden.'<input type="hidden" name="action" value="contar">';
	print sabrooskiposFormConteo($grupos, $stMovil, $keep);
	print '<div class="center"><input type="submit" class="button" value="Registrar conteo del '.dol_escape_htmltag($turno['corto']).'"></div></form>';
} elseif ($paso == 3) {
	// ---- 3. Diferencias del turno ----
	$m = sabrooskiposTurnoMarca($db, $turno['id'], $wh);
	$dif = array();
	$resql = $db->query("SELECT m.fk_product, p.ref, p.label, SUM(m.value) AS v FROM ".MAIN_DB_PREFIX."stock_mouvement m JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = m.fk_product"
		." WHERE m.fk_entrepot = ".((int) $wh)." AND m.inventorycode LIKE 'CIERRE-S".((int) $turno['id'])."-%' GROUP BY m.fk_product, p.ref, p.label ORDER BY p.ref");
	while ($resql && ($o = $db->fetch_object($resql))) {
		if (abs((float) $o->v) > 0.0001) {
			$dif[] = $o;
		}
	}
	$unidad = array();
	foreach ($grupos as $g) {
		foreach ($g['items'] as $it) {
			$unidad[$it['id']] = $it['unidad'] == 'kg' ? 'g' : $it['unidad'];
		}
	}
	print '<h3 class="sbr-title">Diferencias del '.dol_escape_htmltag($turno['corto']).' (conteo de las '.dol_escape_htmltag(substr($m['conteo'], 11, 5)).')</h3>';
	if (!$dif) {
		print '<div class="ok">Sin diferencias: el conteo cuadró con el sistema.</div>';
	} else {
		print '<div class="div-table-responsive"><table class="noborder centpercent sbr-table"><tr class="liste_titre"><td>Producto</td><td>Unidad</td><td class="right">Diferencia (contado − sistema)</td></tr>';
		foreach ($dif as $o) {
			print '<tr class="oddeven"><td>'.dol_escape_htmltag($o->label).' <span class="opacitymedium">'.dol_escape_htmltag($o->ref).'</span></td>'
				.'<td>'.dol_escape_htmltag(isset($unidad[(int) $o->fk_product]) ? $unidad[(int) $o->fk_product] : 'uds').'</td>'
				.'<td class="right'.((float) $o->v < 0 ? ' error' : '').'">'.((float) $o->v > 0 ? '+' : '').sabrooskiposCant((float) $o->v).'</td></tr>';
		}
		print '</table></div>';
	}
	print '<div class="opacitymedium">¿Te equivocaste en el conteo? Corrígelo en <a href="'.dol_buildpath('/sabrooskipos/carga/cierre.php', 1).'?wh='.$wh.'&sesion='.$turno['id'].'">Cierre (conteo)</a>: se ajusta a la nueva cifra.</div><br>';

	// Reposiciones ya registradas después del cierre de caja (van al turno siguiente).
	$o = $db->fetch_object($db->query("SELECT COUNT(DISTINCT inventorycode) AS n FROM ".MAIN_DB_PREFIX."stock_mouvement WHERE fk_entrepot = ".((int) $wh)
		." AND inventorycode LIKE 'CARGA-%' AND value > 0 AND datem > '".$db->escape($turno['t1'])."'"));
	$nrepo = ($o ? (int) $o->n : 0);

	print '<div class="fichecenter"><div class="fichehalfleft">';
	// ---- 3a. Sigue otro turno: reponer ----
	print '<h3 class="sbr-title">Sigue otro turno: reponer desde Principal</h3>';
	if ($nrepo) {
		print '<div class="ok">Ya registraste '.$nrepo.' reposición'.($nrepo > 1 ? 'es' : '').' para el turno siguiente. Si está todo, <b>abre la caja del turno siguiente</b> en el TakePOS.</div>';
	}
	$salio = sabrooskiposSalioEnTurno($db, $turno);
	print '<form method="POST" action="'.$self.'">'.$hidden.'<input type="hidden" name="action" value="reponer">';
	print '<div class="marginbottom"><button type="button" class="button smallpaddingimp" onclick="document.querySelectorAll(\'input[data-salio]\').forEach(function (i) { i.value = i.dataset.salio; });">Reponer lo que salió</button>'
		.' <span class="opacitymedium">(lo vendido, regalado o mermado en el turno, sin pasar de lo que hay en Principal)</span></div>';
	print '<div class="div-table-responsive"><table class="noborder centpercent sbr-table">';
	foreach ($grupos as $g) {
		print '<tr class="liste_titre"><td>'.dol_escape_htmltag($g['titulo']).'</td><td class="right">Salió</td><td class="right">En Principal</td><td class="right">En el móvil</td><td class="right">Reponer</td></tr>';
		foreach ($g['items'] as $it) {
			$f = $it['factor'] > 0 ? $it['factor'] : 1;
			$s = (isset($salio[$it['id']]) ? $salio[$it['id']] : 0);
			$enMain = (isset($stMain[$it['id']]) ? $stMain[$it['id']] : 0);
			$sug = min($s, max(0, $enMain)) / $f;
			$sug = (!empty($it['entero']) ? floor($sug) : round($sug, 3));
			$val = (isset($keep[$it['id']]) ? (string) $keep[$it['id']] : '');
			print '<tr class="oddeven"><td>'.dol_escape_htmltag($it['label']).' <span class="opacitymedium">'.dol_escape_htmltag($it['unidad']).'</span></td>';
			print '<td class="right">'.($s > 0 ? sabrooskiposCant(round($s / $f, 3)) : '').'</td>';
			print '<td class="right">'.sabrooskiposCant($enMain / $f).'</td>';
			print '<td class="right">'.sabrooskiposCant((isset($stMovil[$it['id']]) ? $stMovil[$it['id']] : 0) / $f).'</td>';
			print '<td class="right"><input type="text" inputmode="'.(!empty($it['entero']) ? 'numeric' : 'decimal').'" class="flat right width75" name="qty['.$it['id'].']" value="'.dol_escape_htmltag($val).'"'
				.($sug > 0 ? ' data-salio="'.dol_escape_htmltag((string) price2num($sug)).'"' : '').'></td></tr>';
		}
	}
	print '</table></div>';
	print '<div class="center"><input type="submit" class="button" value="Registrar reposición"></div></form>';
	print '</div><div class="fichehalfright">';
	// ---- 3b. Cierre del día: devolver todo ----
	print '<h3 class="sbr-title">Cierre del día: devolver todo a Principal</h3>';
	print '<div class="'.($esZ ? 'warning' : 'opacitymedium').'">'.($esZ ? 'La caja se cerró con el <b>Reporte Z</b>: es el cierre del día. ' : '')
		.'Después del conteo final, todo lo que queda en el camión vuelve a Principal y el camión queda en 0. '
		.'Mañana se carga de nuevo con <b>Existencia inicial</b>.</div>';
	$neg = array_filter($stMovil, function ($q) {
		return $q < -0.0001;
	});
	if ($neg) {
		print '<div class="warning">Hay '.count($neg).' producto(s) con existencia negativa en el camión: corrígelos con el conteo antes de devolver (si no, quedarán en negativo).</div>';
	}
	$hay = count(array_filter($stMovil, function ($q) {
		return $q > 0.0001;
	}));
	print '<form method="POST" action="'.$self.'">'.$hidden.'<input type="hidden" name="action" value="devolver">';
	print '<div class="center"><input type="submit" class="button'.($esZ ? ' button-save' : '').'" value="Devolver todo a Principal ('.$hay.' productos)"'
		.' onclick="return confirm(\''.dol_escape_js('¿Devolver a Principal todo lo que queda en '.$moviles[$wh].'? El camión quedará en 0.').'\');"></div></form>';
	print '</div></div><div class="clearboth"></div>';
} else {
	// ---- 4. Día cerrado ----
	$quedan = count(array_filter($stMovil, function ($q) {
		return abs($q) > 0.0001;
	}));
	print '<div class="ok"><b>Día cerrado.</b> Lo que quedaba en el camión se devolvió a Principal'.($quedan ? '' : ' y el camión está en 0').'.</div>';
	if ($quedan) {
		print '<div class="warning">Aún quedan '.$quedan.' producto(s) con existencia en el camión (movidos después de la devolución). Revísalos en Cierre (conteo).</div>';
	}
	print '<div class="info">Para el próximo día: <b>Entrada a Principal</b> (producción) → <a href="'.dol_buildpath('/sabrooskipos/carga/index.php', 1).'?tipo=inicial&wh='.$wh.'"><b>Existencia inicial</b></a> → abrir la caja del turno AM en el TakePOS.</div>';
}

llxFooter();
$db->close();
