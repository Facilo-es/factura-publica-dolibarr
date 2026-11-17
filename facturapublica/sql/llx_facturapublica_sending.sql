-- Copyright (C) 2026 Facilo <info@facilo.es>
-- GPL v3 or later. One row per send attempt; the latest row is the invoice's state.

CREATE TABLE llx_facturapublica_sending(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_facture integer NOT NULL,
	environment varchar(8) DEFAULT 'prod' NOT NULL,
	file_path varchar(255),
	file_sha256 varchar(64),
	signed smallint DEFAULT 0 NOT NULL,
	face_registry varchar(32),
	sent_at datetime,
	status_code varchar(8),
	status_text varchar(255),
	status_at datetime,
	cancel_code varchar(8),
	cancel_text varchar(255),
	reason_text text,
	last_check_at datetime,
	last_error text,
	fk_user integer,
	datec datetime,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
