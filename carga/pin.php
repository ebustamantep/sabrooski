<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/carga/pin.php
 * \ingroup sabrooskipos
 * \brief   PIN de autorización de regalías y dañadas: cada autorizador fija el suyo.
 */

require __DIR__.'/main_carga.inc.php';
require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos_bajas.lib.php';

if (!sabrooskiposPuedeAutorizar($user)) {
	accessforbidden('Necesitas el permiso "Autorizar regalías y dañadas (PIN)".');
}

if (GETPOST('action', 'aZ09') == 'guardar') {
	$pin = GETPOST('pin', 'alphanohtml');
	$error = '';
	if ($pin !== GETPOST('pin2', 'alphanohtml')) {
		$error = 'Los dos PIN no coinciden.';
	} elseif (sabrooskiposPinTiene($db, $user) && !sabrooskiposPinVerificar($db, $user, GETPOST('actual', 'alphanohtml'))) {
		$error = 'El PIN actual no es correcto.';
	}
	if ($error === '' && sabrooskiposPinGuardar($db, $user, $pin, $error) > 0) {
		setEventMessages('PIN guardado.', null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
	setEventMessages($error, null, 'errors');
}

$tiene = sabrooskiposPinTiene($db, $user);

llxHeader(sabrooskiposCargaCss(), 'PIN de autorización', '', '', 0, 0, '', '', '', 'mod-sabrooskipos page-pin');
print load_fiche_titre('PIN de autorización', '', 'fa-key');
print '<div class="opacitymedium marginbottom">Con este PIN autorizas las regalías y dañadas que registra el encargado. '
	.'De 4 a 8 dígitos; no lo compartas. '.($tiene ? 'Ya tienes un PIN: para cambiarlo escribe el actual.' : 'Aún no tienes PIN.').'</div>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" autocomplete="off">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="guardar">';
print '<table class="noborder">';
print '<tr class="liste_titre"><td colspan="2">'.dol_escape_htmltag($user->getFullName($langs)).' ('.dol_escape_htmltag($user->login).')</td></tr>';
if ($tiene) {
	print '<tr class="oddeven"><td>PIN actual</td><td><input type="password" name="actual" inputmode="numeric" maxlength="8" class="flat width75" autocomplete="new-password"></td></tr>';
}
print '<tr class="oddeven"><td>PIN nuevo</td><td><input type="password" name="pin" inputmode="numeric" maxlength="8" class="flat width75" autocomplete="new-password"></td></tr>';
print '<tr class="oddeven"><td>Repite el PIN</td><td><input type="password" name="pin2" inputmode="numeric" maxlength="8" class="flat width75" autocomplete="new-password"></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button" value="Guardar PIN"></div>';
print '</form>';

llxFooter();
$db->close();
