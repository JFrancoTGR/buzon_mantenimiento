# Módulo de solicitud de autorización a Dirección

## Alcance

Esta entrega continúa el flujo del ticket con ruta `authorization_required` después de contar con una cotización vigente.

Flujo implementado:

`quotation_pending` → **Solicitar autorización** → `authorization_pending`

La decisión de Dirección (autorizar, rechazar o solicitar cambios) se implementará en el siguiente módulo. Esta entrega únicamente crea y entrega formalmente la solicitud.

## Migraciones

No requiere una nueva migración. Utiliza las estructuras existentes:

- `ticket_approval_requests`
- `approval_statuses`
- `ticket_assignment_history`
- `ticket_status_history`
- `notifications`
- `audit_log`
- `email_log`

La transición `quotation_pending → authorization_pending` ya fue asegurada por la migración 11.

## Instalación

Extraer el parche en:

`public_html/mantenimiento/`

aceptando sobrescrituras.

Después ejecutar:

```bash
php scripts/check_authorization_request_module.php
```

El script valida archivos, tabla de solicitudes, estado `pending`, permisos del supervisor, transición y cotización vigente. También muestra cuántos usuarios activos tienen rol `director`.

## Requisito para enviar una solicitud

Debe existir al menos un usuario:

- `users.status = active`
- con rol activo `director`

La interfaz deshabilita el envío cuando no existe ningún responsable elegible de Dirección.

## Validaciones de backend

`POST /api/tickets/request-authorization.php`

requiere:

- sesión válida;
- cambio de contraseña completado;
- CSRF válido;
- `ticket.request_authorization`;
- `ticket.assign.director`;
- acceso al ticket;
- control del ticket por Supervisor o `ticket.view.all`;
- `row_version` vigente;
- ruta `authorization_required`;
- estado `quotation_pending`;
- cotización seleccionada vigente (`is_current = 1`, `status = current`);
- usuario de Dirección activo y con rol `director`;
- ausencia de otra solicitud `pending` para el ticket;
- justificación obligatoria;
- importe solicitado mayor a cero y no superior al importe de la cotización.

## Operación transaccional

Al enviar correctamente:

1. Inserta `ticket_approval_requests` con estado `pending`.
2. Usa exclusivamente la cotización vigente.
3. Asigna `tickets.director_user_id`.
4. Transfiere `tickets.action_owner_user_id` a Dirección.
5. Cambia `current_status_id` a `authorization_pending`.
6. Incrementa `row_version`.
7. Registra asignaciones de Dirección y responsable actual cuando cambian.
8. Registra `ticket_status_history`.
9. Registra auditoría `ticket.authorization.request`.
10. Genera notificación interna para Dirección.
11. Envía correo específico a Dirección con enlace al expediente.
12. Informa al reportante del cambio de estado cuando es un usuario distinto.

`started_at` permanece `NULL`.

## Interfaz

El expediente incorpora el panel **Autorización de Dirección**.

Antes del envío muestra:

- cotización vigente y versión;
- selector de responsable de Dirección;
- importe solicitado;
- justificación;
- botón `Enviar a Dirección`.

Después del envío muestra la solicitud vigente con:

- número de solicitud;
- estado;
- cotización y versión;
- responsable de Dirección;
- solicitante;
- importe;
- fecha;
- justificación.

El Supervisor deja de ser `action_owner`; conserva acceso al expediente por `supervisor_user_id`.

## Prueba recomendada con MNT-2026-000001

Estado previo esperado:

- `status_code = quotation_pending`
- `processing_route = authorization_required`
- cotización vigente: versión 2
- `director_user_id = NULL`
- `action_owner_user_id = 1`
- `row_version = 5`
- `started_at = NULL`

Para obtener una prueba completa de comunicaciones, usar un usuario de Dirección distinto de Juan Franco Madrid.

Después del envío se espera:

- `status_code = authorization_pending`
- `processing_route = authorization_required`
- `director_user_id = <director seleccionado>`
- `action_owner_user_id = <director seleccionado>`
- `row_version = 6`
- `started_at = NULL`
- una fila `ticket_approval_requests` con estado `pending`
- `quotation_id = 2` si la versión 2 sigue siendo vigente
- `supersedes_request_id = NULL` para la primera solicitud

## Consulta de comprobación

```sql
SELECT
    t.folio,
    s.code AS status_code,
    t.processing_route,
    t.supervisor_user_id,
    t.director_user_id,
    t.action_owner_user_id,
    t.started_at,
    t.row_version,
    ar.id AS approval_request_id,
    aps.code AS approval_status,
    ar.quotation_id,
    q.version_number AS quotation_version,
    ar.requested_amount,
    ar.supersedes_request_id,
    ar.requested_at
FROM tickets t
INNER JOIN ticket_statuses s ON s.id = t.current_status_id
LEFT JOIN ticket_approval_requests ar ON ar.ticket_id = t.id
LEFT JOIN approval_statuses aps ON aps.id = ar.status_id
LEFT JOIN ticket_quotations q ON q.id = ar.quotation_id
WHERE t.folio = 'MNT-2026-000001'
ORDER BY ar.id DESC;
```
