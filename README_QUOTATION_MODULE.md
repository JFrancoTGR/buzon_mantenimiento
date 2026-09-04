# Módulo de Cotizaciones — Supervisor

## Objetivo

Extiende el expediente del ticket para que el supervisor pueda cargar, consultar y versionar cotizaciones PDF dentro de la ruta `authorization_required`.

El módulo no envía todavía la solicitud a Dirección. Esa será la siguiente fase. Una cotización cargada mantiene el ticket en `quotation_pending` y deja el expediente listo para la futura acción `Solicitar autorización`.

## 1. Aplicar migración 12

Ejecutar en phpMyAdmin:

`sql/12_ticket_quotation_validity.sql`

La migración agrega:

- `ticket_quotations.valid_until DATE NULL`
- índice `idx_ticket_quotations_validity (ticket_id, is_current, valid_until)`
- registro `12 — ticket_quotation_validity`

## 2. Instalar el parche

Extraer el ZIP del parche en:

`/domains/estrategiaurbana.info/public_html/mantenimiento/`

Aceptar sobrescritura de archivos existentes.

No reemplazar `.env`.

## 3. Archivos principales

- `app/Services/QuotationService.php`
- `app/Services/TicketDetailService.php`
- `bootstrap/app.php`
- `public/api/tickets/quotations/create.php`
- `public/ticket.html`
- `public/assets/js/modules/ticket-detail.js`
- `public/assets/css/pages/ticket-detail.css`
- `scripts/check_quotation_module.php`
- `sql/12_ticket_quotation_validity.sql`

## 4. Reglas funcionales

La carga de cotizaciones solo está disponible cuando:

- el usuario tiene `ticket.upload.quotation`;
- el ticket está en `quotation_pending`;
- `processing_route = authorization_required`;
- el usuario es el supervisor y responsable actual, o tiene alcance administrativo;
- el `row_version` enviado coincide con el actual.

Campos:

- Proveedor obligatorio.
- Referencia opcional.
- Importe obligatorio, mayor a cero.
- Moneda: MXN.
- Vigencia opcional.
- Descripción del trabajo obligatoria.
- PDF obligatorio.

El límite físico utiliza `system_settings.upload.quotation.max_size_mb` y, en ausencia de una configuración válida, usa 15 MB.

## 5. Versionado

Primera carga:

- `version_number = 1`
- `status = current`
- `is_current = 1`

Al cargar otra cotización:

- la anterior pasa a `status = replaced`, `is_current = 0`;
- la nueva recibe el siguiente `version_number`;
- `previous_quotation_id` apunta a la versión anterior;
- la nueva queda `current`, `is_current = 1`.

Los archivos antiguos no se eliminan.

## 6. Almacenamiento seguro

Los PDF se guardan en:

`storage/uploads/tickets/YYYY/MM/{ticket_id}/quotations/`

Se registra en `ticket_attachments` con:

`attachment_type = quotation`

La descarga utiliza el endpoint autenticado existente:

`/api/tickets/attachment.php?id={attachment_id}`

El acceso directo a `storage/` continúa bloqueado por `.htaccess`.

Además, los adjuntos de tipo `quotation` requieren permisos internos relacionados con cotización/autorización. Un usuario reportante no puede obtener un PDF de cotización aunque conozca el ID del adjunto.

## 7. Efectos sobre el ticket

Cargar una cotización:

- no cambia el estado;
- no inicia el trabajo;
- no asigna Dirección;
- incrementa `row_version`;
- actualiza `updated_at`;
- registra `ticket.quotation.create` en auditoría.

No se envían correos en esta operación para evitar notificaciones intermedias. La comunicación a Dirección se realizará al crear la solicitud formal de autorización.

## 8. Validación CLI

Después de aplicar la migración y el parche:

`php scripts/check_quotation_module.php`

Debe finalizar con:

`VALIDACIÓN CORRECTA`

Antes de cargar una cotización en `MNT-2026-000001`, el script debe mostrar el ticket en:

- `quotation_pending`
- `authorization_required`
- `row_version = 3`

## 9. Prueba funcional — versión 1

Abrir `MNT-2026-000001`.

En la sección Cotizaciones:

1. Abrir `Cargar cotización`.
2. Capturar proveedor, importe y descripción.
3. Adjuntar un PDF válido.
4. Guardar.
5. Confirmar que aparece `Cotización vigente · Versión 1`.
6. Abrir el PDF mediante `Ver PDF`.
7. Descargar mediante `Descargar`.

Resultado esperado:

- estado continúa `quotation_pending`;
- `processing_route` continúa `authorization_required`;
- `row_version = 4`;
- existe 1 `ticket_quotation` actual;
- existe 1 `ticket_attachment` tipo `quotation`.

## 10. Prueba funcional — versión 2

Abrir nuevamente el formulario y cargar un segundo PDF.

Resultado esperado:

- nueva cotización `version_number = 2`, `current`, `is_current = 1`;
- versión 1 `replaced`, `is_current = 0`;
- versión 2 `previous_quotation_id = id` de versión 1;
- `row_version = 5`;
- ambos PDF permanecen físicamente almacenados y disponibles para usuarios internos autorizados.
