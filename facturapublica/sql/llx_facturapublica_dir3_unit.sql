-- Copyright (C) 2026 Facilo <info@facilo.es>
-- Local copy of FACe's administrations and units, for the DIR3 finder.
CREATE TABLE llx_facturapublica_dir3_unit(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	admin_code varchar(9) NOT NULL,
	admin_name varchar(255),
	oc_code varchar(9),
	oc_name varchar(255),
	og_code varchar(9),
	og_name varchar(255),
	ut_code varchar(9),
	ut_name varchar(255),
	fetched_at datetime
) ENGINE=innodb;
