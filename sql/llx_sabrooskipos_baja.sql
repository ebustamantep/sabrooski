-- Regalías y dañadas registradas en el cierre del móvil (autorizadas con PIN).
CREATE TABLE llx_sabrooskipos_baja (
	rowid             integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity            integer DEFAULT 1 NOT NULL,
	datec             datetime NOT NULL,
	fecha             date NOT NULL,
	fk_entrepot       integer NOT NULL,
	tipo              varchar(16) NOT NULL,
	fk_product        integer NOT NULL,
	qty               double(24,8) NOT NULL,
	sabores           varchar(255) DEFAULT '',
	motivo            varchar(255) NOT NULL,
	costo             double(24,8) DEFAULT 0,
	inventorycode     varchar(64) NOT NULL,
	fk_user_author    integer NOT NULL,
	fk_user_autoriza  integer NOT NULL,
	status            integer DEFAULT 1 NOT NULL,
	date_anula        datetime NULL,
	fk_user_anula     integer NULL,
	fk_user_autoriza_anula integer NULL
) ENGINE=innodb;
