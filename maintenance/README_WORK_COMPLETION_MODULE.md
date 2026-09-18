# Módulo: Terminación del trabajo

## Alcance

Implementa la transición `in_progress → completed` para tickets de ruta directa o con autorización.

El Supervisor responsable debe registrar:

- resumen de la solución (10–5000 caracteres);
- fecha y hora real de terminación;
- al menos una evidencia fotográfica final JPG/JPEG/PNG;
- observaciones finales opcionales.

## Persistencia

La operación actualiza `tickets.resolution_summary`, `tickets.completed_at`, `current_status_id` y `row_version`. Las imágenes se guardan como `ticket_attachments.attachment_type = completion_evidence` bajo `storage/uploads/tickets/YYYY/MM/{ticket_id}/completion/`.

También registra `ticket_status_history`, auditoría `ticket.execution.complete`, notificaciones internas y correo de cambio de estado a los demás participantes.

## Seguridad

El backend exige sesión, CSRF, `ticket.change_status`, `ticket.upload.evidence`, estado `in_progress`, `started_at` existente, `completed_at` nulo, `row_version` vigente y que el usuario sea simultáneamente `supervisor_user_id` y `action_owner_user_id`.

Las evidencias usan validación MIME real, `getimagesize`, límite de dimensiones, tamaño configurado en `upload.evidence.max_size_mb`, nombres aleatorios y SHA-256. `storage/` continúa sin exposición directa.

## Instalación

No requiere migración SQL. Extraer el parche en `public_html/mantenimiento/` y ejecutar:

```bash
php scripts/check_work_completion_module.php
```

## Prueba sugerida

Actualmente `MNT-2026-000001` y `MNT-2026-000002` pueden converger en `in_progress`. Para validar ambos caminos se puede terminar primero uno de ellos.

Después de completar `MNT-2026-000001`, se espera:

- `status_code = completed`;
- `processing_route = authorization_required`;
- `completed_at` no nulo;
- `started_at` preservado;
- `authorized_at` preservado;
- `action_owner_user_id = supervisor_user_id`;
- `row_version = 9` (partiendo del estado actual 8);
- al menos una fila `ticket_attachments` con `attachment_type = completion_evidence`.

El estado `completed` queda preparado para el siguiente módulo: cierre del ticket.
