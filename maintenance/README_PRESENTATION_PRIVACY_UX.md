# Parche final de presentación, privacidad y UX — MVP

Este parche se aplica sobre la versión que ya incluye el flujo de revisión de cotización (`mantenimiento_quotation_revision_fix`). No modifica la lógica de negocio del workflow ni requiere migración SQL.

## Objetivos

1. Separar formalmente el expediente interno Supervisor/Dirección de la vista pública del Reporter.
2. Mostrar las solicitudes de autorización con numeración secuencial por ticket, sin exponer el ID global como número de solicitud.
3. Compactar el historial del ticket en bloques visuales de 5 eventos.
4. Convertir la campana en un contador real de notificaciones no leídas usando `notifications.read_at`.

## Instalación

Extraer el contenido del ZIP del parche directamente sobre:

```text
public_html/mantenimiento/
```

No ejecutar SQL adicional.

Después validar desde la raíz del proyecto:

```bash
php scripts/check_presentation_ux.php
```

El script valida archivos, política de presentación, privacidad de comunicaciones, numeración de solicitudes, historial compacto y endpoints de notificaciones. En el servidor también mostrará datos auxiliares de la base para los usuarios Reporter y tickets con múltiples solicitudes.

## 1. Vista pública del Reporter

Los estados físicos en MariaDB permanecen intactos. Para el Reporter se presenta el siguiente mapa público:

```text
new                                                        → Nuevo
under_review / quotation_pending / authorization_pending
changes_requested / authorized                             → En gestión
in_progress                                                → En proceso
completed / closed                                         → Trabajo terminado
```

El Reporter deja de recibir o visualizar detalles de la operación interna Supervisor ↔ Dirección:

- proveedor;
- cotizaciones y versiones;
- documentos de cotización;
- importes solicitados o autorizados;
- justificaciones de autorización;
- solicitudes y decisiones de Dirección;
- comentarios de decisión;
- cambios solicitados por Dirección;
- asignaciones internas Supervisor ↔ Director;
- ruta interna de atención.

En el detalle del ticket, para Reporter:

- Dirección no se muestra como participante;
- la ruta aparece como `Gestión interna`;
- cotizaciones y autorizaciones siguen ocultas;
- el historial elimina transiciones que sólo representan pasos administrativos internos;
- las notas usadas en transiciones de estado no se exponen;
- los comentarios normales de `ticket_comments` continúan siendo públicos para los participantes.

La auditoría, estados físicos, cotizaciones, autorizaciones y el historial completo permanecen disponibles para Supervisor, Director y Administrator según sus permisos actuales.

## 2. Comunicaciones al Reporter

A partir del parche, las operaciones Supervisor ↔ Dirección no generan correo ni notificación pública al Reporter.

El Reporter continúa recibiendo hitos públicos, por ejemplo:

```text
Reporte recibido
En gestión
En proceso
Trabajo terminado
Nuevo comentario público
```

Los correos de `En gestión` y `En proceso` son deliberadamente genéricos y no incluyen las notas operativas capturadas por el Supervisor.

Los correos que ya fueron enviados antes de instalar este parche no pueden modificarse retrospectivamente. Las notificaciones internas históricas permanecen en la base de datos, pero se filtran de la campana del Reporter.

## 3. Numeración de solicitudes de autorización

La PK global `ticket_approval_requests.id` no cambia y continúa utilizándose para las relaciones y endpoints.

El backend agrega un `display_number` calculado por ticket en orden cronológico:

```text
primera solicitud  → Solicitud 1
segunda solicitud  → Solicitud 2
tercera solicitud  → Solicitud 3
```

Por ejemplo, `MNT-2026-000004` puede tener IDs internos globales `2` y `3`, pero la UI debe mostrar `Solicitud 1` y `Solicitud 2`.

## 4. Historial compacto

El backend sigue entregando el historial permitido completo. La compactación es únicamente de presentación en frontend.

La vista muestra inicialmente los 5 eventos más recientes:

```text
Mostrando 5 de 14 eventos
[Cargar 5 más]
```

Cada clic agrega otros 5. Al mostrar todos los eventos desaparece el botón.

Para Reporter, primero se aplica la política de privacidad y después la paginación visual. Para perfiles internos se pagina el historial interno completo.

## 5. Campana de notificaciones

El badge representa exclusivamente notificaciones visibles y no leídas:

```text
read_at IS NULL
```

Se agregan dos operaciones:

```text
POST /api/notifications/read.php
POST /api/notifications/read-all.php
```

### Lectura individual

Al pulsar una notificación no leída:

1. se marca como leída;
2. se actualiza el contador;
3. se navega a su `action_url`.

El backend siempre condiciona la actualización por el usuario de sesión:

```text
notification.id = solicitado
AND notification.user_id = session_user_id
```

Por tanto un usuario no puede marcar como leída una notificación ajena manipulando el ID.

### Marcar todas como leídas

La campana incorpora:

```text
Marcar todas como leídas
```

que establece `read_at` para todas las notificaciones pendientes del usuario actual y lleva el badge a cero. Las filas no se eliminan.

## QA recomendado con los tickets actuales

### Como QAU Reporter

Abrir `MNT-2026-000004` y verificar:

- estado visible `Trabajo terminado`;
- ruta `Gestión interna`;
- no aparece QAU Director en Responsables;
- no aparecen Cotizaciones ni Autorización de Dirección;
- historial público compacto, sin importes, solicitudes, reasignaciones ni comentarios de decisión;
- inicialmente se muestran como máximo 5 eventos y aparece `Cargar 5 más` cuando corresponde.

Revisar también la campana:

- el badge ya no debe incluir notificaciones históricas internas de cotización/autorización;
- abrir una notificación reduce el badge;
- `Marcar todas como leídas` lleva el badge a 0.

### Como QAU Supervisor / QAU Director / Administrator

Abrir `MNT-2026-000004` y verificar:

- el expediente interno conserva toda su información;
- V1 y V2 continúan disponibles;
- las decisiones y comentarios de Dirección continúan visibles;
- las solicitudes aparecen como `Solicitud 1` y `Solicitud 2`, aunque sus IDs internos sean distintos;
- el historial interno se muestra en bloques de 5, sin pérdida de eventos.

## Archivos principales modificados

```text
app/Services/TicketPresentationPolicy.php       NUEVO
app/Services/NotificationService.php             NUEVO
app/Services/DashboardService.php
app/Services/SupervisorService.php
app/Services/TicketDetailService.php
app/Services/QuotationService.php
app/Services/AuthorizationRequestService.php
app/Services/AuthorizationDecisionService.php
bootstrap/app.php
public/api/notifications/read.php                NUEVO
public/api/notifications/read-all.php            NUEVO
public/assets/js/components/notificationsMenu.js
public/assets/js/modules/dashboard.js
public/assets/js/modules/ticket-detail.js
public/assets/js/modules/tickets.js
public/assets/css/components/dropdown.css
public/assets/css/pages/ticket-detail.css
scripts/check_presentation_ux.php                NUEVO
```

## Alcance

Este parche está deliberadamente limitado a presentación, privacidad de datos y UX. No modifica las transiciones de negocio ya validadas, la matriz RBAC, el versionamiento de cotizaciones, las decisiones de Dirección ni el cierre automático del ticket.
