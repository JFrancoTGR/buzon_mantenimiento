# Módulo de decisión de Dirección

Este parche extiende el flujo de autorización de la Plataforma de Mantenimiento para que el responsable de Dirección asignado pueda revisar y resolver una solicitud pendiente.

## Alcance

- Mantiene el dashboard actual de Dirección.
- Asegura que Supervisor y Dirección asignados puedan visualizar cotizaciones y solicitudes con `ticket.view.assigned`, separando visibilidad de permisos de acción.
- Muestra al Director la cotización vigente, versiones anteriores y la solicitud pendiente.
- Agrega tres decisiones:
  - Autorizar.
  - Solicitar cambios.
  - Rechazar.
- Agrega control de concurrencia mediante `row_version` y `SELECT ... FOR UPDATE`.
- Registra decisión, historial de estado, historial de responsable, auditoría, notificación y correo.
- No requiere migración SQL.

## Archivos principales

- `app/Services/AuthorizationDecisionService.php`
- `public/api/tickets/authorization-decision.php`
- `app/Services/TicketDetailService.php`
- `app/Services/MailerService.php`
- `public/assets/js/modules/ticket-detail.js`
- `public/assets/css/pages/ticket-detail.css`
- `bootstrap/app.php`
- `scripts/check_director_decision_module.php`

## Reglas de negocio

La decisión solamente puede procesarse cuando:

- El ticket está en `authorization_pending`.
- La ruta es `authorization_required`.
- Existe una solicitud `pending`.
- La solicitud pertenece al ticket.
- El usuario debe tener rol `director`, ser el Director asignado, responsable actual y approver de la solicitud. `ticket.view.all` solo concede visibilidad y no permite decidir.
- El usuario tiene el permiso específico de la decisión.
- `row_version` coincide con la versión que vio el usuario.

### Autorizar

Requiere comentario e importe autorizado.

- `ticket_approval_requests.status` → `approved`
- `decided_by_user_id` → Director
- `approved_amount` → importe autorizado
- `responded_at` → UTC actual
- `tickets.current_status` → `authorized`
- `tickets.action_owner_user_id` → Supervisor
- `tickets.authorized_at` → UTC actual
- `tickets.row_version` + 1
- Cotización vigente → `status = approved`

El importe autorizado debe ser mayor a cero y no puede superar el importe solicitado.

### Solicitar cambios

Requiere comentario.

- solicitud → `changes_requested`
- ticket → `changes_requested`
- responsable actual → Supervisor
- `row_version` + 1

La cotización se conserva. El siguiente módulo del Supervisor permitirá regresar a `quotation_pending`, cargar una nueva versión y reenviar la solicitud.

### Rechazar

Requiere comentario.

- solicitud → `rejected`
- ticket → `rejected`
- responsable actual → `NULL`
- `row_version` + 1

`rejected` es estado final del ticket.

## Instalación

Extraer el parche en:

`public_html/mantenimiento/`

Aceptar sobrescritura de archivos.

No ejecutar migraciones.

## Validación CLI

```bash
php scripts/check_director_decision_module.php
```

Antes de decidir el ticket actual debe mostrar aproximadamente:

```text
MNT-2026-000001
status=authorization_pending
route=authorization_required
supervisor=1
director=4
owner=4
row_version=6

Solicitud #1
status=pending
quotation=2
approver=4
amount=45000.00
```

## Prueba recomendada con el escenario actual

Iniciar sesión como `QAU Director`, abrir `MNT-2026-000001` y comprobar:

1. Se muestran Cotizaciones y Autorización de Dirección.
2. La solicitud #1 aparece como Pendiente.
3. Se muestran los botones `Autorizar`, `Solicitar cambios` y `Rechazar`.
4. Elegir **Autorizar** para continuar el escenario principal.
5. Autorizar `$45,000.00 MXN` con un comentario de prueba.

Resultado esperado:

```text
tickets.status = authorized
tickets.director_user_id = 4
tickets.action_owner_user_id = 1
tickets.row_version = 7
tickets.authorized_at = fecha no nula
tickets.started_at = NULL
```

La solicitud debe quedar:

```text
approval_status = approved
decided_by_user_id = 4
approved_amount = 45000.00
responded_at = fecha no nula
```

La cotización #2 debe seguir siendo `is_current = 1` y pasar a `status = approved`.

El Supervisor/Reportante Juan recibe una sola notificación/correo en el escenario QA actual porque ambas responsabilidades pertenecen al mismo usuario.
