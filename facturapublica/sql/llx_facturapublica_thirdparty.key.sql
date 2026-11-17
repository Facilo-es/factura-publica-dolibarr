-- Copyright (C) 2026 Facilo <info@facilo.es>
ALTER TABLE llx_facturapublica_thirdparty ADD UNIQUE INDEX uk_facturapublica_thirdparty_soc(fk_soc, entity);
