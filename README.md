# Factura Pública para Dolibarr (Lite)

**Cobra antes de la Administración.** Factura Pública convierte las facturas que ya validas en Dolibarr en archivos Facturae 3.2.2 listos para FACe, el punto de entrada de facturas del sector público. Sin portales, sin copiar datos a mano y sin facturas rechazadas por un código DIR3 mal puesto.

Es un módulo de [Facilo](https://facilo.es). Más información y versión completa: **https://facturapublica.facilo.es**

## Guías

- [Enviar una factura a FACe desde Dolibarr](https://facturapublica.facilo.es/guias/enviar-factura-face-dolibarr/)
- [Facturae en Dolibarr: crear y validar el fichero](https://facturapublica.facilo.es/guias/facturae-dolibarr/)
- [Firmar la factura electrónica con tu certificado](https://facturapublica.facilo.es/guias/firmar-factura-electronica/)
- [Códigos DIR3: qué son y dónde ponerlos](https://facturapublica.facilo.es/guias/codigos-dir3/)
- [Estados de FACe: consultar tu factura y qué hacer](https://facturapublica.facilo.es/guias/estados-factura-face/)
- [Facturar a un ayuntamiento con Dolibarr](https://facturapublica.facilo.es/guias/facturar-ayuntamiento-dolibarr/)

Todas las guías: https://facturapublica.facilo.es/guias/ · Firma, envío a FACe y seguimiento hasta el pago con la [versión completa](https://facturapublica.facilo.es/#precio).

## Qué consigues con la versión Lite (gratis)

- Generas el archivo Facturae 3.2.2 de cualquier factura validada, con un clic desde la propia factura.
- Guardas los códigos DIR3 (oficina contable, órgano gestor y unidad tramitadora) en cada cliente público, una sola vez.
- Compruebas el archivo contra el esquema oficial antes de subirlo, para que no te lo devuelvan.
- Lo descargas sin firmar y lo subes tú a FACe o a otro portal.

## Qué añade Factura Pública completa (149 €, pago único)

- Firma con el certificado de tu empresa (XAdES) y envío directo a FACe desde Dolibarr.
- Seguimiento diario del estado hasta que la factura se paga o se rechaza.
- Envío de varias facturas a la vez, anulación de envíos, buscador DIR3 y facturas rectificativas.
- Copia en el archivo la URL de cotejo VERI*FACTU que ya ha generado tu sistema de facturación, sin cambiarla.

Cómprala en https://facturapublica.facilo.es/#precio. Si instalas la completa encima de la Lite, conservas todos tus datos.

## Instalación

1. Descarga [`dist/module_facturapublica-1.0.0.zip`](dist/module_facturapublica-1.0.0.zip).
2. En Dolibarr, ve a **Inicio > Configuración > Módulos > Instalar un módulo externo** y sube el zip.
3. Activa **Factura Pública** en la lista de módulos.

Funciona con Dolibarr 19 a 24 y PHP 7.4 o superior. El código fuente está en la carpeta [`facturapublica/`](facturapublica/).

## Qué no hace

Factura Pública no expide facturas ni genera registros VERI*FACTU, y no envía nada a la AEAT: trabaja con las facturas que Dolibarr ya ha validado. Nunca crea, numera, modifica ni borra una factura.

## Licencia y contacto

GPL-3.0 o posterior (ver [LICENSE](LICENSE)). Usa [facturae-php](https://github.com/josemmo/Facturae-PHP) (MIT). Dudas: info@facilo.es.

---

## English

**Factura Pública Lite** turns the invoices you already validate in Dolibarr into Facturae 3.2.2 files ready for FACe, Spain's public-sector invoice hub. Store the DIR3 codes once per public customer, check the file against the official schema and download it to upload yourself.

The full edition (EUR 149, one-off) signs with your company certificate, sends straight to FACe from Dolibarr and tracks each invoice until it is paid or rejected. See https://facturapublica.facilo.es.

Install: download the zip in `dist/`, upload it under **Home > Setup > Modules > Deploy external module**, then enable Factura Pública. Licence GPL-3.0-or-later.
