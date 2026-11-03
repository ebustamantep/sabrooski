<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/manual/index.php
 * \ingroup sabrooskipos
 * \brief   Manual de usuario del módulo (menú SabrooskiVentas). El texto vive en manual.md.
 */

require __DIR__.'/../carga/main_carga.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/parsemd.lib.php';

if (!$user->admin && !$user->hasRight('sabrooskipos', 'use', 'use') && !$user->hasRight('sabrooskipos', 'reportes', 'read')
	&& !$user->hasRight('sabrooskipos', 'carga', 'write') && !$user->hasRight('sabrooskipos', 'bajas', 'autorizar')) {
	accessforbidden();
}

$md = (string) @file_get_contents(__DIR__.'/manual.md');

$css = '<style>
.sabmanual { max-width: 980px; line-height: 1.5; }
.sabmanual h1 { font-size: 1.6em; margin: .2em 0 .6em; }
.sabmanual h2 { font-size: 1.3em; margin: 1.6em 0 .5em; padding-bottom: .2em; border-bottom: 1px solid #ddd; }
.sabmanual h3 { font-size: 1.1em; margin: 1.2em 0 .4em; }
.sabmanual table { border-collapse: collapse; margin: .6em 0 1em; width: 100%; }
.sabmanual th, .sabmanual td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; vertical-align: top; }
.sabmanual th { background: #f3f3f3; }
.sabmanual pre { background: #f6f6f6; padding: 8px 10px; border-radius: 4px; overflow-x: auto; }
.sabmanual code { background: #f3f3f3; padding: 0 3px; border-radius: 3px; }
.sabmanual pre code { background: none; padding: 0; }
.sabmanual blockquote { margin: .6em 0; padding: 4px 12px; border-left: 4px solid #e8a33d; background: #fff8ec; }
.sabmanual .sabtoc { background: #f8f8f8; border: 1px solid #e3e3e3; padding: 8px 14px; margin-bottom: 1em; }
.sabmanual .sabtoc a { display: inline-block; margin-right: 14px; }
@media print { #id-left, #id-top, .side-nav, .login_block, .sabtoc { display: none !important; } }
</style>';

llxHeader($css, 'Manual de usuario', '', '', 0, 0, '', '', '', 'mod-sabrooskipos page-manual');

if ($md === '') {
	print '<div class="warning">No se encontró el archivo manual/manual.md del módulo.</div>';
} else {
	$html = dolMd2Html($md);
	// Ancla en cada sección (##) para el índice de arriba
	$toc = array();
	$html = preg_replace_callback('/<h2>(.*?)<\/h2>/', function ($m) use (&$toc) {
		$id = 'sec'.(count($toc) + 1);
		$toc[] = '<a href="#'.$id.'">'.$m[1].'</a>';
		return '<h2 id="'.$id.'">'.$m[1].'</h2>';
	}, $html);
	$indice = '<div class="sabtoc">'.implode(' ', $toc).' <a href="#" onclick="window.print();return false;">'.img_picto('', 'printer', 'class="pictofixedwidth"').'Imprimir</a></div>';
	$pos = strpos($html, '</h1>');
	$html = ($pos === false ? $indice.$html : substr_replace($html, '</h1>'.$indice, $pos, 5));
	print '<div class="sabmanual">'.$html.'</div>';
}

llxFooter();
$db->close();
