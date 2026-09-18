# Módulo de inicio de ejecución

Implementa el paso operativo `authorized -> in_progress` para tickets de la ruta `authorization_required`.

## Instalación

No requiere migración SQL. La transición ya fue creada por la migración 11.

Copiar el parche sobre la raíz del proyecto y ejecutar:

```bash
php scripts/check_execution_start_module.php
```

## Reglas

La acción solo está disponible cuando:

- el ticket está en `authorized`;
- `processing_route = authorization_required`;
- existe `authorized_at`;
- `started_at` todavía es `NULL`;
- la cotización vigente está marcada `approved`;
- existe una solicitud de autorización `approved` vinculada con esa cotización;
- el usuario tiene `ticket.change_status`;
- el usuario coincide con `supervisor_user_id` y `action_owner_user_id`.

`ticket.view.all` no otorga por sí solo capacidad para iniciar la ejecución.

## Efectos esperados

Para `MNT-2026-000001` antes de la prueba:

```text
status_code          = authorized
processing_route     = authorization_required
row_version          = 7
started_at           = NULL
```

Después de **Iniciar ejecución**:

```text
status_code          = in_progress
processing_route     = authorization_required
row_version          = 8
started_at           = fecha UTC no nula
action_owner_user_id = supervisor_user_id
```

No se modifican `authorized_at`, `director_user_id`, la solicitud aprobada ni la cotización autorizada.

También se registra:

- historial `authorized -> in_progress`;
- auditoría `ticket.execution.start`;
- notificación/correo al reportante cuando es una cuenta distinta del supervisor.
