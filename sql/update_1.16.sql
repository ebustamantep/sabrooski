-- v1.16: cada regalía/dañada queda ligada a la sesión de caja (turno) en que se registró.
ALTER TABLE llx_sabrooskipos_baja ADD COLUMN fk_cashsession integer NULL;
