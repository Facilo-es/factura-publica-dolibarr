-- Copyright (C) 2026 Facilo <info@facilo.es>
-- GPL v3 or later. See docs/COPYING.

CREATE TABLE llx_facturapublica_thirdparty(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_soc integer NOT NULL,
	is_public smallint DEFAULT 0 NOT NULL,
	dir3_oc varchar(9),
	dir3_og varchar(9),
	dir3_ut varchar(9),
	dir3_op varchar(9),
	needs_file_ref smallint DEFAULT 0 NOT NULL,
	notify_email varchar(255),
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
