-- Copyright (C) 2026 Facilo <info@facilo.es>
ALTER TABLE llx_facturapublica_sending ADD INDEX idx_facturapublica_sending_fac(fk_facture);
ALTER TABLE llx_facturapublica_sending ADD INDEX idx_facturapublica_sending_status(status_code);
