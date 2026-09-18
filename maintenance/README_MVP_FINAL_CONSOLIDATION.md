# Consolidación final del MVP — Roles + cierre automático

Esta entrega consolida el flujo completo de la Plataforma de Mantenimiento antes de volver a probarlo con tickets nuevos.

## Objetivos

1. Separar definitivamente las responsabilidades de `reporter`, `supervisor`, `director` y `administrator`.
2. Hacer que cada cuenta tenga un único perfil principal.
3. Eliminar la fecha/hora manual de terminación.
4. Tomar `completed_at` y `closed_at` desde el servidor en UTC.
5. Convertir `completed` en un estado transitorio de trazabilidad.
6. Cerrar automáticamente el ticket al finalizar el trabajo.
7. Actualizar dashboard y bandeja para contar `closed` como **Terminados**.
8. Eliminar el fallback que utilizaba una cuenta Administrator como Supervisor.

---

## 1. Orden de instalación

### Paso A — Ejecutar migración 14

Ejecutar en phpMyAdmin:

```text
sql/14_mvp_role_matrix_auto_close.sql
```

La migración:

- normaliza roles;
- crea `uq_user_roles_single_role`;
- reemplaza la matriz completa de `role_permissions`;
- configura `completed → closed` como transición automática;
- lleva a `closed` cualquier ticket que actualmente permanezca en `completed`;
- establece `action_owner_user_id = NULL`;
- conserva `completed_at` como `closed_at`;
- incrementa `row_version` una sola vez en el backfill.

Los tickets QA `MNT-2026-000001` y `MNT-2026-000002` deberían quedar cerrados después de esta migración.

### Paso B — Aplicar parche

Extraer:

```text
mantenimiento_mvp_final_patch.zip
```

directamente sobre:

```text
public_html/mantenimiento/
```

aceptando sobrescrituras.

### Paso C — Validación

```bash
php scripts/check_mvp_final.php
```

El núcleo debe terminar con:

```text
VALIDACIÓN CORRECTA
```

El script puede mostrar `CONFIGURAR` en las ubicaciones hasta que exista un Supervisor real. Esto es deliberado.

---

# 2. Matriz RBAC definitiva

## Reporter

```text
ticket.create
ticket.view.own
ticket.comment
ticket.upload.evidence
```

Puede originar reportes, consultar los propios, comentar y aportar evidencia.

## Supervisor

```text
ticket.create
ticket.view.own
ticket.view.assigned
ticket.comment
ticket.assign.director
ticket.change_status
ticket.upload.evidence
ticket.upload.quotation
ticket.request_authorization
```

Es el responsable del flujo operativo:

```text
Nuevo
→ En revisión
→ definir ruta
→ cotización cuando aplica
→ solicitar autorización
→ iniciar ejecución
→ registrar terminación
```

También puede crear tickets nuevos.

## Director

```text
ticket.view.assigned
ticket.comment
ticket.authorize
ticket.reject
ticket.request_changes
```

No tiene `ticket.change_status`, exportación, creación, cotizaciones ni otras capacidades del Supervisor.

Sus tres decisiones se ejecutan únicamente mediante los endpoints específicos de autorización.

## Administrator

```text
ticket.view.all
ticket.export
user.manage
role.manage
catalog.manage
audit.view
```

Administrator es gobierno del sistema:

- visibilidad global;
- gestión de usuarios;
- roles/permisos;
- catálogos;
- auditoría;
- exportación.

No puede:

- crear tickets;
- comentar;
- iniciar revisión;
- definir ruta;
- cargar cotizaciones;
- solicitar autorización;
- autorizar/rechazar;
- iniciar ejecución;
- finalizar trabajos.

---

# 3. Un perfil por cuenta

La migración crea:

```text
uq_user_roles_single_role (user_id)
```

Por tanto, una cuenta ya no puede ser simultáneamente:

```text
administrator + supervisor
```

o:

```text
reporter + director
```

Para cambiar el perfil desde base de datos debe retirarse primero el rol anterior y después insertarse el nuevo.

Esto es exactamente lo que hará posteriormente el módulo administrativo de Usuarios.

---

# 4. Configurar un Supervisor QA antes de crear tickets nuevos

Después de la migración, las ubicaciones que todavía apunten al usuario Administrator aparecerán como:

```text
Sin supervisor configurado
```

Esto es correcto: **Administrator ya no es un Supervisor válido**.

Para QA conviene:

1. registrar una nueva cuenta con correo real;
2. verificarla normalmente;
3. promoverla desde MariaDB de `reporter` a `supervisor`;
4. asignarla temporalmente a las ubicaciones que se usarán en la prueba.

Ejemplo. Cambiar el correo antes de ejecutar:

```sql
USE `u170017077_mantenimiento`;

START TRANSACTION;

SET @supervisor_email := 'SUPERVISOR_QA@DOMINIO.COM';

SET @supervisor_user_id := (
    SELECT id
    FROM users
    WHERE email = @supervisor_email
      AND status = 'active'
      AND email_verified_at IS NOT NULL
    LIMIT 1
);

SET @reporter_role_id := (
    SELECT id FROM roles WHERE code = 'reporter' LIMIT 1
);

SET @supervisor_role_id := (
    SELECT id FROM roles WHERE code = 'supervisor' LIMIT 1
);

SET @administrator_user_id := (
    SELECT id
    FROM users
    WHERE email = 'jfranco@estrategiaurbana.com.mx'
    LIMIT 1
);

DELETE FROM user_roles
WHERE user_id = @supervisor_user_id
  AND role_id = @reporter_role_id;

INSERT INTO user_roles (
    user_id,
    role_id,
    assigned_by_user_id,
    assigned_at
)
VALUES (
    @supervisor_user_id,
    @supervisor_role_id,
    @administrator_user_id,
    UTC_TIMESTAMP()
);

-- Para QA puede asignarse temporalmente a las cuatro ubicaciones.
UPDATE locations
SET default_supervisor_user_id = @supervisor_user_id
WHERE code IN (
    'showroom_naos',
    'showroom_the_wavve',
    'showroom_estrategia_urbana',
    'corporativo_estrategia_urbana'
);

COMMIT;
```

Después volver a ejecutar:

```bash
php scripts/check_mvp_final.php
```

Las cuatro ubicaciones deberían mostrar `OK`.

En producción se sustituirá esta asignación temporal por los Supervisores reales correspondientes a cada ubicación.

---

# 5. Nuevo comportamiento al crear tickets

`TicketService` ya no utiliza al Administrator como fallback.

Una ubicación solo es válida operativamente cuando:

```text
users.status = active
AND
rol único = supervisor
```

Si no existe Supervisor configurado, la UI deshabilita esa sede y el backend rechaza de todas formas cualquier intento de creación.

Esto evita que un error de configuración convierta silenciosamente al Administrador en responsable del negocio.

Si un Supervisor crea un ticket en una ubicación donde él mismo es Supervisor, no se duplica la notificación/correo de asignación a sí mismo; la confirmación de creación es suficiente.

---

# 6. Finalizar trabajo = cerrar ticket

El formulario ya no solicita:

```text
Fecha y hora real de terminación
```

Solo captura:

```text
Resumen de la solución *
Evidencias finales *
Observaciones
```

Al confirmar **Finalizar trabajo**, el backend obtiene una única marca temporal desde MariaDB:

```text
UTC_TIMESTAMP()
```

y ejecuta una sola modificación lógica del expediente:

```text
in_progress
→ completed
→ closed
```

Persistiendo:

```text
resolution_summary
completed_at = timestamp servidor
closed_at    = mismo timestamp
action_owner_user_id = NULL
row_version = row_version + 1
```

`completed` sigue existiendo para historial, pero el ticket no permanece estacionado allí.

En `ticket_status_history` quedan dos eventos:

```text
En proceso → Trabajo terminado
Trabajo terminado → Cerrado
```

El segundo tiene:

```text
change_source = system
```

La comunicación externa continúa siendo una sola:

```text
Trabajo terminado
```

No se envía un segundo correo de `Ticket cerrado`.

---

# 7. Dashboard y bandeja

La tarjeta:

```text
Terminados
```

ahora cuenta tickets con estado:

```text
closed
```

y muestra:

```text
Tickets cerrados
```

Los tickets cerrados:

- no aparecen en Acciones pendientes;
- no cuentan como `Requieren mi atención`;
- tienen `action_owner_user_id = NULL`.

Además, la bandeja corrige el filtro de **Autorizados**, que ahora utiliza realmente `authorized`.

---

# 8. Prueba completa recomendada con tickets nuevos

Una vez configurados los cuatro perfiles:

```text
Administrator → Juan
Reporter      → Emilio
Supervisor    → nueva cuenta QA
Director      → QAU Director
```

crear dos reportes nuevos.

## Ticket A — ruta con autorización

```text
Reporter crea
→ Supervisor inicia revisión
→ Requiere cotización
→ Cotización V1 / V2
→ Solicitar autorización
→ Director autoriza
→ Supervisor inicia ejecución
→ Supervisor registra solución + evidencias
→ closed automático
```

Resultado final esperado:

```text
status = closed
processing_route = authorization_required
completed_at != NULL
closed_at = completed_at
action_owner_user_id = NULL
```

## Ticket B — ruta directa

```text
Reporter crea
→ Supervisor inicia revisión
→ Atención directa
→ En proceso
→ Supervisor registra solución + evidencias
→ closed automático
```

Resultado:

```text
status = closed
processing_route = direct
authorized_at = NULL
completed_at != NULL
closed_at = completed_at
action_owner_user_id = NULL
```

---

# 9. Validación final SQL sugerida

```sql
SELECT
    t.folio,
    s.code AS status_code,
    t.processing_route,
    t.reported_by_user_id,
    t.supervisor_user_id,
    t.director_user_id,
    t.action_owner_user_id,
    t.authorized_at,
    t.started_at,
    t.completed_at,
    t.closed_at,
    t.row_version
FROM tickets t
INNER JOIN ticket_statuses s
    ON s.id = t.current_status_id
ORDER BY t.id DESC
LIMIT 10;
```

Para tickets finalizados por el flujo nuevo:

```text
status_code = closed
action_owner_user_id = NULL
completed_at = closed_at
```
