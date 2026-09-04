# Parche — nueva cotización después de cambios solicitados

Este parche corrige el stopper detectado en la ruta con autorización cuando Dirección selecciona **Solicitar cambios**.

## Regla de negocio

`changes_requested` significa exclusivamente que la propuesta/cotización debe corregirse. El Supervisor recupera la responsabilidad y debe poder cargar una nueva versión.

Al guardar la cotización corregida, una sola operación transaccional realiza:

- V1 (o versión vigente anterior) → `status = replaced`, `is_current = 0`.
- Nueva versión → `status = current`, `is_current = 1`, `previous_quotation_id` apunta a la versión anterior.
- Ticket: `changes_requested → quotation_pending`.
- `action_owner_user_id` permanece en el Supervisor.
- `row_version + 1` una sola vez.
- Se agrega historial de estado y auditoría `ticket.quotation.revision.submit`.
- Se notifica al Reportante del regreso a **Cotización pendiente**; no se notifica al propio actor.
- La solicitud de autorización anterior se conserva intacta con estado `changes_requested`.

Después, el Supervisor puede crear una nueva solicitud de autorización. `AuthorizationRequestService` ya enlaza la nueva solicitud mediante `supersedes_request_id` a la última solicitud con cambios solicitados.

## Archivos modificados

- `bootstrap/app.php`
- `app/Services/QuotationService.php`
- `app/Services/TicketDetailService.php`
- `public/assets/js/modules/ticket-detail.js`

Archivo de comprobación:

- `scripts/check_quotation_revision_flow.php`

## Instalación

No requiere migración SQL.

Extraer el ZIP del parche directamente sobre la raíz del proyecto, por ejemplo:

```text
public_html/mantenimiento/
```

Después ejecutar:

```bash
php scripts/check_quotation_revision_flow.php
```

Con `MNT-2026-000004` todavía en `changes_requested` debe indicar:

```text
LISTO PARA CARGAR COTIZACIÓN CORREGIDA
VALIDACIÓN CORRECTA
```

## Prueba inmediata con MNT-2026-000004

1. Iniciar sesión como **QAU Supervisor**.
2. Abrir `MNT-2026-000004`.
3. Debe aparecer el formulario **Cargar nueva versión para atender correcciones** y el botón lateral **Cargar nueva cotización**.
4. Cargar V2.
5. Confirmar que el ticket cambia automáticamente a **Cotización pendiente**.
6. Confirmar que V1 aparece en versiones anteriores como reemplazada y V2 como vigente.
7. Confirmar que vuelve a aparecer **Solicitar autorización**.
8. Enviar una nueva solicitud a QAU Director.
9. La nueva solicitud debe conservar la trazabilidad de la solicitud anterior mediante `supersedes_request_id`.

No se modifica el RBAC: el Supervisor sigue necesitando `ticket.upload.quotation`, `ticket.change_status`, ser el Supervisor asignado y ser el `action_owner` actual.
