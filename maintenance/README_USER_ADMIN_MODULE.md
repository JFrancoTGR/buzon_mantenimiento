# Módulo de Administración de Usuarios — V1

## Estado esperado de la BDD

Requiere la migración `15_user_administration_invitations.sql` aplicada.

La migración habilita:

- `users.status = invited`.
- `users.password_hash` nullable para identidades todavía no activadas.
- `user_invitation_tokens` con token hash, caducidad, uso y revocación.

En la BDD QA actual la migración 15 ya fue aplicada y validada.

## Principios del módulo

- Administrator administra identidades, roles y estado; nunca conoce contraseñas.
- Un usuario invitado nace como `invited` con `password_hash = NULL`.
- El usuario crea su propia contraseña desde un enlace de un solo uso.
- El token plano nunca se guarda en MariaDB ni se devuelve al frontend administrativo.
- El enlace usa `#token=...` (fragmento), no query string; la validación se envía por POST + CSRF para evitar exponer el token en access logs.
- La invitación vigente por defecto dura 72 horas.
- Emitir una nueva invitación revoca las anteriores no utilizadas.
- No existe eliminación física de usuarios desde este módulo.
- Cambios de rol y desactivaciones revocan las sesiones existentes.
- No se permite modificar el rol propio ni desactivar la propia cuenta.
- No se permite degradar/desactivar al último Administrator activo.
- Supervisor no puede perder su rol/estado mientras sea supervisor por defecto de una ubicación activa o conserve tickets activos.
- Director no puede perder su rol/estado mientras tenga autorizaciones pendientes.

## Archivos nuevos

### Backend

- `app/Services/UserAdminService.php`
- `app/Services/UserInvitationService.php`

### API administrativa

- `public/api/admin/users/context.php`
- `public/api/admin/users/list.php`
- `public/api/admin/users/create.php`
- `public/api/admin/users/resend-invitation.php`
- `public/api/admin/users/revoke-invitation.php`
- `public/api/admin/users/change-role.php`
- `public/api/admin/users/change-status.php`

### API pública de invitación

- `public/api/auth/invitation-context.php`
- `public/api/auth/accept-invitation.php`

### Frontend

- `public/users.html`
- `public/accept-invitation.html`
- `public/assets/js/modules/users.js`
- `public/assets/js/modules/accept-invitation.js`
- `public/assets/css/users.css`

## Archivos existentes reemplazados/integrados

### `bootstrap/app.php`

El patch incluye una versión actualizada que registra:

- `user_invitations`
- `user_admin`

### `public/assets/js/core/permissions.js`

El patch activa automáticamente el enlace de `Usuarios` en los shells existentes que todavía lo tienen marcado como `data-coming-soon`. De esta manera no es necesario editar manualmente cada HTML previo.

### `app/Services/MailerService.php`

No se reemplaza completo para evitar pisar futuras modificaciones. Después de copiar el patch, ejecutar:

```bash
php scripts/apply_user_module_integration.php
```

El script:

1. detecta si `sendUserInvitationEmail()` ya existe;
2. crea un backup `MailerService.php.before-users-module.bak`;
3. inserta el método de invitación antes del bloque de correos de tickets;
4. ejecuta `php -l`;
5. restaura el backup automáticamente si la sintaxis resultante falla.

## Variables de entorno

Todas tienen defaults y por tanto no son obligatorias, pero se recomienda declararlas:

```dotenv
USER_INVITATION_TTL_HOURS=72
USER_INVITATION_RESEND_COOLDOWN_MINUTES=10
USER_INVITATION_RESEND_MAX_PER_HOUR=5
```

`APP_TIMEZONE` ya se utiliza para mostrar la fecha absoluta de expiración en el correo. La plataforma sigue persistiendo fechas de seguridad en UTC.

## Instalación sobre la rama actual

1. Respaldar proyecto y BDD.
2. Confirmar que migración 15 está aplicada.
3. Copiar el contenido de este patch sobre la raíz del proyecto respetando rutas.
4. Ejecutar:

```bash
php scripts/apply_user_module_integration.php
```

5. Ejecutar validación:

```bash
php scripts/check_user_admin_module.php
```

6. Abrir `/mantenimiento/users.html` con el Administrator actual.

## QA recomendado con plus addressing

Crear una identidad nueva, por ejemplo:

```text
juan.francomadrid+supervisor@gmail.com
```

Secuencia principal:

1. Crear usuario desde `Usuarios`.
2. Confirmar `status=invited`, `password_hash=NULL`.
3. Recibir correo real.
4. Abrir enlace y crear contraseña.
5. Confirmar `status=active`, `email_verified_at` y hash.
6. Confirmar sesión automática y dashboard.
7. Probar cambio de rol y verificar revocación de sesión.
8. Probar desactivación/reactivación.
9. Crear otra invitación y validar revocación del token anterior.
10. Probar manualmente un token revocado/consumido: debe responder 410.

## Auditoría agregada

- `user.create`
- `user.invitation.create`
- `user.invitation.resend`
- `user.invitation.revoke`
- `user.invitation.accept`
- `user.invitation.delivery_failed`
- `user.role.change`
- `user.activate`
- `user.deactivate`
- `auth.session.user_invitation`

Correo:

- `auth.user_invitation.requested`

## No incluido todavía

- eliminación física de usuarios;
- edición de correo/nombre después del alta;
- editor de matriz rol → permisos;
- administración de catálogos;
- visor administrativo de auditoría;
- reset QA → productivo.

El reset QA → productivo debe diseñarse y ejecutarse después de validar este módulo y antes del go-live definitivo, preservando esquema/catálogos/RBAC y reiniciando tickets/folios/datos de prueba según lo acordado.
