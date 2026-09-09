# Account / Self-Service V1

Este parche agrega autoservicio para cuentas autenticadas.

## Alcance
- `profile.html` para cualquier usuario autenticado.
- Edición de `first_name` y `last_name`.
- Correo, rol y estado sólo lectura.
- Cambio voluntario de contraseña usando `AuthService::changePassword()`.
- Revocación de todas las sesiones tras cambio de contraseña.
- Auditoría `account.profile.update`.
- Navegación centralizada desde el dropdown existente.
- Ruta `profile.html` agregada al allowlist del `.htaccess`.

## No incluido
- Cambio de correo.
- Recuperación de contraseña sin sesión.
- Edición de rol/estado por el propio usuario.

## QA CLI
Desde la raíz del proyecto:

```bash
php scripts/check_account_self_service.php
```
