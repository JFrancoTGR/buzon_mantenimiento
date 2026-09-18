# Dashboard V1 — Plataforma de Mantenimiento

## Alcance incluido

Esta entrega sustituye el dashboard técnico de roles y permisos por una primera interfaz operativa:

- Layout unificado con sidebar, topbar y contenido principal.
- Menú dinámico de acuerdo con los permisos efectivos del usuario.
- Tarjetas de tickets nuevos, en revisión, por autorizar, en proceso y terminados.
- Tabla de acciones pendientes con alcance por usuario.
- Actividad reciente basada en `ticket_status_history`.
- Menú de usuario y cierre de sesión.
- Indicador y listado de notificaciones internas.
- Diseño responsive para escritorio, tablet y móvil.
- Estados vacíos cuando todavía no hay tickets.
- SweetAlert2 para confirmaciones y módulos todavía no implementados.

También se actualiza el flujo obligatorio de contraseña:

1. El usuario cambia su contraseña temporal.
2. Se actualiza `must_change_password = 0`.
3. Se revocan todas sus sesiones.
4. Se destruye la sesión PHP actual.
5. SweetAlert2 confirma el cambio.
6. El navegador vuelve a `login.html`.

## Instalación sobre la versión actual

1. Realizar respaldo de los archivos existentes.
2. Extraer el ZIP de parche directamente dentro de:

   ```text
   public_html/mantenimiento/
   ```

3. Permitir que el sistema reemplace los archivos con la misma ruta.
4. No eliminar ni reemplazar el archivo `.env` real.
5. Confirmar que el `.env` contenga:

   ```dotenv
   SESSION_COOKIE_PATH=/mantenimiento
   ```

6. No se requieren scripts SQL adicionales para esta versión.

## Archivos backend nuevos

```text
app/Services/DashboardService.php
public/api/dashboard/summary.php
public/api/dashboard/pending-actions.php
public/api/dashboard/recent-activity.php
scripts/check_dashboard.php
```

## Endpoints

```text
GET /mantenimiento/api/dashboard/summary.php
GET /mantenimiento/api/dashboard/pending-actions.php
GET /mantenimiento/api/dashboard/recent-activity.php
```

Los tres endpoints requieren:

- Sesión válida.
- Usuario activo.
- Cambio obligatorio de contraseña ya completado.

El alcance de los tickets se calcula así:

- `ticket.view.all`: todos los tickets.
- `ticket.view.assigned`: tickets asignados como supervisor, Dirección o responsable de acción.
- `ticket.view.own`: tickets creados por el usuario.

## Validación CLI

Desde la raíz del proyecto:

```bash
php scripts/check_dashboard.php
```

También puede indicarse un usuario concreto:

```bash
php scripts/check_dashboard.php jfranco@estrategiaurbana.com.mx
```

Resultado esperado en una base sin tickets:

```text
VALIDACIÓN DEL DASHBOARD
=======================
Contadores: {"new":0,"under_review":0,"authorization_pending":0,"in_progress":0,"completed":0}
Notificaciones sin leer: 0
Acciones pendientes obtenidas: 0
Actividades recientes obtenidas: 0
VALIDACIÓN CORRECTA
```

## Validación web

1. Iniciar sesión con la contraseña definitiva.
2. Confirmar que se abra:

   ```text
   https://estrategiaurbana.info/mantenimiento/dashboard.html
   ```

3. Verificar en Network que respondan `200`:

   ```text
   api/auth/me.php
   api/dashboard/summary.php
   api/dashboard/pending-actions.php
   api/dashboard/recent-activity.php
   ```

4. Confirmar que el dashboard muestre ceros y estados vacíos si no existen tickets.
5. Reducir la ventana para verificar la apertura del sidebar móvil.
6. Cerrar sesión desde el menú del usuario.

## Notas

- Los enlaces de Tickets, Nuevo reporte, Usuarios, Catálogos, Auditoría y Mi cuenta muestran por ahora una alerta de “Módulo en preparación”.
- Los endpoints ya consultan la base real, por lo que las tarjetas y listados comenzarán a mostrar información cuando se creen tickets.
- No se muestran roles, permisos, IDs ni datos técnicos al usuario final.
