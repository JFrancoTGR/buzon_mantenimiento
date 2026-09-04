# Módulo de Supervisor — primera entrega

## Alcance

Esta entrega incorpora:

- Bandeja unificada de tickets en `tickets.html`.
- Alcance automático por permisos:
  - `ticket.view.all`: todos los tickets.
  - `ticket.view.assigned`: tickets asignados.
  - `ticket.view.own`: reportes propios.
- Contadores operativos para supervisor.
- Filtros por estado, ruta, ubicación, prioridad, responsabilidad y búsqueda.
- Selección transaccional de la ruta desde el expediente:
  - `direct` → `in_progress`.
  - `authorization_required` → `quotation_pending`.
- Control de concurrencia mediante `row_version` y `SELECT ... FOR UPDATE`.
- Historial de estado con metadatos de la ruta.
- Auditoría `ticket.route.select`.
- Notificación interna y correo al reportante.
- Actualización dinámica de la tarjeta **Siguiente acción**.

La carga de cotizaciones y la solicitud a Dirección quedan para la siguiente entrega.

## Requisito de base de datos

La migración 11 debe estar aplicada y validada:

```text
11 — ticket_processing_routes
```

El parche incluye una copia en:

```text
sql/11_ticket_processing_routes.sql
```

No es necesario volver a ejecutarla cuando ya aparece registrada.

## Instalación

Extraer el parche en:

```text
public_html/mantenimiento/
```

Aceptar la sobrescritura de archivos. El parche no contiene `.env`, credenciales ni archivos cargados por usuarios.

## Validación CLI

```bash
php scripts/check_supervisor_module.php
```

Para validar el alcance del administrador actual:

```bash
php scripts/check_supervisor_module.php jfranco@estrategiaurbana.com.mx
```

El resultado final esperado es:

```text
VALIDACIÓN CORRECTA
```

## Prueba funcional

### Ruta directa

1. Abrir `MNT-2026-000001`.
2. Confirmar que está `under_review` y `processing_route = NULL`.
3. Seleccionar **Atender directamente**.
4. Capturar un plan de atención obligatorio.
5. Confirmar:

```text
processing_route = direct
status_code = in_progress
row_version = 3
started_at != NULL
```

### Ruta con autorización

1. Abrir `MNT-2026-000002`.
2. Seleccionar **Requiere cotización**.
3. Agregar una nota opcional.
4. Confirmar:

```text
processing_route = authorization_required
status_code = quotation_pending
row_version = 3
started_at = NULL
```

Emilio debe recibir la notificación interna y el correo correspondiente porque es el reportante del segundo ticket.

## Consulta de verificación

```sql
SELECT
    t.folio,
    s.code AS status_code,
    t.processing_route,
    t.started_at,
    t.action_owner_user_id,
    t.row_version,
    t.updated_at
FROM tickets t
INNER JOIN ticket_statuses s
    ON s.id = t.current_status_id
WHERE t.folio IN (
    'MNT-2026-000001',
    'MNT-2026-000002'
)
ORDER BY t.folio;
```

## Seguridad aplicada

El endpoint `POST /api/tickets/select-route.php` valida:

- Sesión activa y contraseña actualizada.
- CSRF.
- Permiso `ticket.change_status`.
- Acceso al ticket.
- Supervisor y responsable actual, salvo administrador con alcance total.
- Estado `under_review`.
- Ruta todavía no seleccionada.
- Valor permitido de `processing_route`.
- Transición activa en catálogo.
- Comentario obligatorio para atención directa.
- Versión vigente del ticket.

La ruta no puede cambiarse después de seleccionarse mediante este endpoint.
