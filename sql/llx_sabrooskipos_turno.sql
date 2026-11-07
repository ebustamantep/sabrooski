-- Turno = sesión de caja de POSPlus (llx_posplus_cashsession). Aquí se marca, por turno y
-- almacén, cuándo se hizo el conteo de cierre y la devolución a Principal (un conteo sin
-- diferencias no deja movimientos de stock, por eso hace falta la marca).
CREATE TABLE llx_sabrooskipos_turno (
	rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity          integer DEFAULT 1 NOT NULL,
	fk_cashsession  integer NOT NULL,
	fk_entrepot     integer NOT NULL,
	conteo_date     datetime NULL,
	conteo_code     varchar(64) NULL,
	fk_user_conteo  integer NULL,
	devol_date      datetime NULL,
	devol_code      varchar(64) NULL,
	fk_user_devol   integer NULL
) ENGINE=innodb;
