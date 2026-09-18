# Vista de detalle del ticket — V1

Este parche incorpora el expediente operativo del ticket sobre la plataforma existente.

## Funcionalidades

- Vista `ticket.html?id={ticket_id}`.
- Acceso según `ticket.view.all`, `ticket.view.own` o `ticket.view.assigned`.
- Información general, responsables, prioridad, estado y zona específica.
- Galería de evidencias mediante endpoint protegido.
- Descarga autenticada de archivos; `storage` continúa bloqueado por HTTP.
- Comentarios visibles para todos los participantes con permiso `ticket.comment`.
- Historial combinado de estados y asignaciones.
- Primera transición operativa: `Nuevo → En revisión`.
- Validación de la transición contra `ticket_status_transitions` y permisos efectivos.
- Control de concurrencia mediante `tickets.row_version`.
- Auditoría, notificaciones internas y correos en comentarios/cambio de estado.
- Enlaces del dashboard, notificaciones nuevas y correos nuevos dirigidos al expediente.
- Después de crear un reporte, el SweetAlert conduce directamente al ticket.

## Instalación

Extrae el ZIP del parche directamente en:

```text
public_html/mantenimiento/
```

Acepta la sobrescritura de archivos. El parche no contiene `.env` ni requiere una migración SQL.

## Validación CLI

Desde la raíz del proyecto:

```bash
php scripts/check_ticket_detail.php
```

Para verificar además el alcance de un usuario concreto:

```bash
php scripts/check_ticket_detail.php jfranco@estrategiaurbana.com.mx
```

## Prueba funcional

1. Abre el dashboard.
2. Selecciona **Ver ticket** en `MNT-2026-000001`.
3. Confirma que carguen datos, tres evidencias, historial y responsables.
4. Abre una imagen y comprueba que se sirve por `api/tickets/attachment.php`.
5. Agrega un comentario.
6. Selecciona **Iniciar revisión**.
7. Confirma que el estado cambie a `En revisión`, el contador del dashboard se actualice y aparezca el nuevo movimiento.

Para probar correos entre usuarios distintos, crea un ticket con la cuenta reportante de QA y realiza la revisión con la cuenta administradora/supervisora.

## Endpoints

```text
GET  /api/tickets/detail.php?id={ticket_id}
POST /api/tickets/comment.php
POST /api/tickets/transition.php
GET  /api/tickets/attachment.php?id={attachment_id}
```

## Alcance de esta versión

La transición habilitada desde la UI es únicamente `Nuevo → En revisión`. Las cotizaciones, asignación de Dirección y solicitudes de autorización se desarrollarán en fases posteriores para evitar saltarse sus reglas de negocio.
