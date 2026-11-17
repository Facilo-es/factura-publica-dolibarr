-- Copyright (C) 2026 Facilo <info@facilo.es>
CREATE TABLE llx_facturapublica_status_log(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_sending integer NOT NULL,
	code varchar(8) NOT NULL,
	text varchar(255),
	at datetime NOT NULL
) ENGINE=innodb;
