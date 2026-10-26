<?php
/* Copyright (C) 2026		Edgar Bustamante
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    sabrooskipos/core/triggers/interface_99_modSabrooskiPOS_Recetas.class.php
 * \ingroup sabrooskipos
 * \brief   Descuenta el mix por sabor de los servidos al validar un ticket TakePOS.
 *
 * Los insumos fijos de la receta (vaso, cono, cucharilla…) los descuenta Dolibarr solo
 * (kits, PRODUIT_SOUSPRODUITS). El mix depende del sabor elegido en el popup, así que lo
 * mueve este trigger: ver sabrooskiposMoverMixFactura() en lib/sabrooskipos_recetas.lib.php.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Trigger de recetas de SabrooskiPOS
 */
class InterfaceRecetas extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = "products";
		$this->description = "SabrooskiPOS: descuenta el mix por sabor de los servidos al validar un ticket TakePOS.";
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'sabrooskipos@sabrooskipos';
	}

	/**
	 * Ejecuta el trigger
	 *
	 * @param string    $action Código del evento
	 * @param Object    $object Objeto
	 * @param User      $user   Usuario
	 * @param Translate $langs  Traducciones
	 * @param Conf      $conf   Configuración
	 * @return int              <0 si KO, 0 si nada, >0 si OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('sabrooskipos')) {
			return 0;
		}
		if (!in_array($action, array('BILL_VALIDATE', 'BILL_UNVALIDATE', 'BILL_DELETE'))) {
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/custom/sabrooskipos/lib/sabrooskipos_recetas.lib.php';

		if ($action == 'BILL_VALIDATE') {
			// Solo si la validación descontó stock (el TakePOS fuerza STOCK_CALCULATE_ON_BILL).
			if (!getDolGlobalString('STOCK_CALCULATE_ON_BILL')) {
				return 0;
			}
			$r = sabrooskiposMoverMixFactura($this->db, $user, $object, 'validar');
		} else {
			$r = sabrooskiposMoverMixFactura($this->db, $user, $object, 'revertir');
		}
		if ($r < 0) {
			$this->errors[] = 'SabrooskiPOS: no se pudo mover el mix de '.$object->ref;
			return -1;
		}
		return $r;
	}
}
