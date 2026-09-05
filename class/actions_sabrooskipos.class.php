<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/actions_sabrooskipos.class.php
 * \ingroup    sabrooskipos
 * \brief      Clase de hooks sobre el TakePOS nativo (botón "Toma de orden")
 *
 * Se engancha al contexto 'takeposfrontend' (declarado en el descriptor del módulo):
 *   - ActionButtons   → agrega el botón "Toma de orden" a la barra de acciones.
 *   - addHtmlHeader   → inyecta el JS que abre el popup y su CSS.
 *
 * No reemplaza nada de TakePOS: solo agrega un botón y un popup.
 */
class ActionsSabrooskipos
{
	/**
	 * @var array<string,mixed> Conjunto de resultados para el hookmanager
	 */
	public $results = array();

	/**
	 * @var string|null Resultados a imprimir (resPrint)
	 */
	public $resprints;

	/**
	 * Hook ActionButtons (takepos/index.php ~1535).
	 * Agrega el botón "Toma de orden" a la barra de acciones, conservando los
	 * botones nativos (devolvemos 0 = agregar, no reemplazar).
	 *
	 * @param array        $parameters   Parámetros del hook (contiene 'menus')
	 * @param object       $object       Objeto en curso
	 * @param string       $action       Acción en curso
	 * @param HookManager  $hookmanager  Gestor de hooks
	 * @return int 0 = OK y conservar lo nativo
	 */
	public function ActionButtons($parameters, &$object, &$action, $hookmanager)
	{
		if (empty($parameters['currentcontext']) || $parameters['currentcontext'] != 'takeposfrontend') {
			return 0;
		}

		// El hookmanager hace array_merge_recursive de $this->results. En
		// takepos/index.php cada elemento de resArray se recorre con
		// "foreach ($resArray as $butmenu)", así que cada item debe ser un ARRAY
		// de botones (un botón más aunque sea uno solo).
		$this->results[] = array(
			array(
				'title' => '<span class="fa fa-ice-cream paddingrightonly"></span><div class="trunc">Toma de orden</div>',
				'action' => 'SabrooskiTomaDeOrden();',
			)
		);

		return 0; // 0 = conservar botones nativos y sumar el nuestro
	}

	/**
	 * Hook addHtmlHeader (main.inc.php:2064, dentro de top_htmlhead).
	 * Inyecta la función JS SabrooskiTomaDeOrden() que abre el picker (popup
	 * colorbox, mismo patrón que Reduction/floors/split) y el CSS del modal.
	 *
	 * @param array        $parameters   Parámetros del hook
	 * @param object       $object       Objeto en curso
	 * @param string       $action       Acción en curso
	 * @param HookManager  $hookmanager  Gestor de hooks
	 * @return int 0 = OK
	 */
	public function addHtmlHeader($parameters, &$object, &$action, $hookmanager)
	{
		if (empty($parameters['currentcontext']) || $parameters['currentcontext'] != 'takeposfrontend') {
			return 0;
		}

		$this->resprints = '
		<script>
		function SabrooskiTomaDeOrden(){
			if (typeof jQuery === "undefined" || typeof $.colorbox !== "function") { return; }
			var invoiceid = (jQuery("#invoiceid").val() || "");
			$.colorbox({
				href: "'.DOL_URL_ROOT.'/custom/sabrooskipos/picker/index.php?place="+place+"&invoiceid="+invoiceid+"&token='.newToken().'",
				width: "95%",
				height: "92%",
				transition: "none",
				iframe: "true",
				title: "Toma de orden"
			});
		}
		</script>
		<link rel="stylesheet" type="text/css" href="'.DOL_URL_ROOT.'/custom/sabrooskipos/css/picker.css">';

		return 0;
	}
}
