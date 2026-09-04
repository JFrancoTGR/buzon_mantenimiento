# Frontera Administración / Dirección

Este parche corrige la separación de responsabilidades entre el rol `administrator` y el rol `director`.

## Regla aplicada

El administrador mantiene acceso global al expediente mediante `ticket.view.all`, pero dicho permiso ya no funciona como un bypass para resolver solicitudes de autorización.

Para autorizar, rechazar o solicitar cambios se exige simultáneamente:

1. permiso específico (`ticket.authorize`, `ticket.reject` o `ticket.request_changes`);
2. rol activo `director`;
3. ser `tickets.director_user_id`;
4. ser `tickets.action_owner_user_id`;
5. ser `ticket_approval_requests.approver_user_id` de la solicitud pendiente.

La migración 13 retira del rol `administrator` únicamente los tres permisos de decisión de Dirección. No modifica todavía otras capacidades operativas del administrador, porque la política sobre edición/cambios de estado/cotizaciones se definirá por separado.

## Instalación

1. Ejecutar `sql/13_administrator_authorization_boundary.sql` en `u170017077_mantenimiento`.
2. Copiar/sobrescribir los archivos del parche en `public_html/mantenimiento/`.
3. Ejecutar:

```bash
php scripts/check_admin_authorization_boundary.php
```

Resultado esperado:

```text
Administrador sin permisos de decisión: OK
Dirección conserva authorize/reject/request_changes: OK
Migración 13 registrada: OK
VALIDACIÓN CORRECTA
```

## QA del ticket MNT-2026-000001

Con Juan (administrator):
- puede ver el panel y la solicitud;
- no debe ver botones Autorizar / Solicitar cambios / Rechazar;
- un POST directo al endpoint de decisión debe responder 403.

Con QAU Director:
- conserva los tres botones;
- puede resolver la solicitud porque es director, approver y action owner actual.
