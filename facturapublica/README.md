# Factura Pública

Cobra antes de las administraciones públicas sin salir de Dolibarr. Abre una factura validada, pulsa **Enviar a FACe** y el módulo prepara el archivo Facturae 3.2.2, lo firma con el certificado de tu empresa y lo entrega en FACe. Después sigue la factura cada día hasta que se paga o se rechaza.

Factura Pública no expide facturas ni genera registros VERI*FACTU: trabaja con las facturas que Dolibarr ya ha validado.

## Qué hace

- Revisa la factura antes de enviarla y te dice cada problema en palabras claras, con un enlace para arreglarlo.
- Crea el archivo Facturae 3.2.2 y comprueba que coincide céntimo a céntimo con los totales de Dolibarr.
- Lo firma con tu certificado (XAdES, política Facturae). El certificado no sale nunca de tu servidor.
- Lo envía a FACe y guarda el número de registro.
- Consulta el estado cada día y te avisa si la factura se rechaza o se paga.
- Te deja anular un envío en FACe (eso no anula la factura: para corregirla, emite una rectificativa), enviar varias facturas a la vez y descargar el archivo firmado o sin firmar para otros portales (como e-FACT).
- Busca los códigos DIR3 del organismo por nombre, para que no tengas que escribirlos.
- Gestiona facturas rectificativas y copia en el archivo la URL de cotejo VERI*FACTU que ya ha generado tu sistema de facturación, sin cambiarla.

## Qué no hace

- No crea, numera, modifica ni borra facturas. Solo lee las que Dolibarr ya ha validado.
- No genera registros VERI*FACTU ni envía nada a la AEAT. Eso corresponde a Dolibarr o a su módulo VERI*FACTU.
- No garantiza que el organismo acepte la factura: esa decisión es suya.
- No envía a portales distintos de FACe (para ellos, descarga el archivo firmado).

## Requisitos

- Dolibarr 19.0 a 24.x, PHP 7.4 o superior con las extensiones openssl, curl y dom.
- Un certificado digital de tu empresa (persona física o representante de persona jurídica) en formato .p12 o .pfx.
- La parte pública del certificado registrada en el portal de proveedores de FACe. El asistente te da la clave y el enlace.

## Instalación

1. En Dolibarr, ve a Inicio > Configuración > Módulos > Desplegar módulo externo y sube `module_facturapublica-1.0.0.zip`.
2. Activa **Factura Pública** en la lista de módulos.
3. Abre su configuración y sigue el asistente: certificado, entorno (pruebas o producción) y opciones.

Al desactivar el módulo se conservan sus tablas y tus datos. Si marcas «Borrar también el certificado» en las opciones, el certificado se borra del servidor al desactivarlo.

## Soporte

support@facilo.es · Facilo, https://facilo.es

## English

Factura Pública turns an invoice already validated in Dolibarr into a Facturae 3.2.2 file, signs it with your company certificate (XAdES) and sends it to FACe, Spain's public-sector invoice hub, then tracks it daily until it is paid or rejected. It also offers bulk sending, cancelling, a DIR3 code finder, credit notes, and copies the VERI*FACTU check URL your billing system already created into the file, unchanged. Cancelling a submission at FACe does not cancel the invoice: issue a credit note for that.

Factura Pública does not issue invoices or create VERI*FACTU records: it works with the invoices Dolibarr has already validated. It never creates, numbers, edits or deletes an invoice, and the certificate never leaves your server.

Install it through Home > Setup > Modules > Deploy an external module, enable it, then follow the setup wizard.

## Licence

GPL v3 or later, see `docs/LICENSE`. Includes facturae-php 1.8.6 by josemmo, MIT licence, see `docs/LICENSE-facturae-php.txt` and `lib/vendor/facturae-php/FACILO-CHANGES.md`.
