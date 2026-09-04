# Fundación de autenticación — Plataforma de Mantenimiento

Paquete compatible con PHP 8.1+ y MariaDB. Implementa:

- Conexión PDO centralizada.
- Configuración mediante `.env` fuera del directorio público.
- Alta segura del primer administrador desde CLI.
- Login contra la tabla `users`.
- Hash y verificación mediante `password_hash()` y `password_verify()`.
- Bloqueo temporal por intentos fallidos.
- Sesiones registradas en `user_sessions`.
- Cookies `HttpOnly`, `Secure` y `SameSite`.
- Protección CSRF.
- Logout con revocación de sesión.
- Cambio obligatorio de contraseña inicial.
- Consulta de roles y permisos efectivos.
- Auditoría de accesos y cambios de contraseña.
- Páginas mínimas para probar el flujo completo.

## 1. Requisitos

- PHP 8.1 o superior.
- Extensiones `pdo_mysql`, `json` y `openssl`.
- HTTPS en producción.
- Tablas y semillas previamente creadas.

## 2. Estructura de publicación

La raíz pública del dominio debe apuntar a:

```text
mantenimiento_auth_foundation/public
```

Las carpetas `app`, `bootstrap`, `scripts`, `sql`, `storage` y el archivo `.env` no deben quedar accesibles desde la web.

## 3. Configuración

Copiar:

```bash
cp .env.example .env
```

Editar `.env` con las credenciales reales de MariaDB.

En desarrollo local sin HTTPS puede utilizarse temporalmente:

```dotenv
SESSION_SECURE_COOKIE=false
APP_ENV=local
APP_DEBUG=true
```

En producción debe mantenerse:

```dotenv
SESSION_SECURE_COOKIE=true
APP_DEBUG=false
```

## 4. Crear el primer administrador

Desde la raíz del proyecto:

```bash
php scripts/create_initial_admin.php
```

El script solicitará nombre, apellidos, correo y contraseña temporal. No imprime ni almacena la contraseña en texto plano.

La contraseña debe:

- Tener al menos 12 caracteres.
- Combinar por lo menos tres categorías entre minúsculas, mayúsculas, números y símbolos.

La cuenta se crea activa, con rol `administrator` y con `must_change_password = 1`.

## 5. Validar el administrador

```bash
php scripts/verify_initial_admin.php administrador@dominio.com
```

La salida esperada debe indicar:

```text
VALIDACIÓN CORRECTA
```

También puede utilizarse `sql/08_validate_auth_setup.sql` desde phpMyAdmin después de reemplazar el correo de ejemplo.

## 6. Probar el login

Abrir:

```text
https://tu-dominio/login.html
```

Flujo esperado:

1. Login con contraseña temporal.
2. Redirección obligatoria a `change-password.html`.
3. Cambio de contraseña.
4. Redirección a `dashboard.html`.
5. Visualización de roles y permisos efectivos.
6. Logout y revocación de la sesión en MariaDB.

## 7. Endpoints incluidos

```text
GET  /api/auth/csrf.php
POST /api/auth/login.php
GET  /api/auth/me.php
POST /api/auth/change-password.php
POST /api/auth/logout.php
```

Todos los POST requieren el encabezado:

```text
X-CSRF-Token
```

## 8. Reglas importantes

- No colocar `.env` dentro de `public`.
- No versionar `.env`.
- No desactivar `SESSION_SECURE_COOKIE` en producción.
- No crear administradores mediante SQL con contraseñas en texto plano.
- Los endpoints futuros deben llamar a `currentUser()` y después validar permisos con `AuthorizationService`.
- Mientras `must_change_password` sea verdadero, el resto de módulos debe bloquearse mediante `AuthorizationService::requirePasswordChanged()`.

## 9. Integración en un endpoint futuro

```php
<?php

use App\Core\Http;
use App\Services\AuthorizationService;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';
$user = $services['auth']->currentUser();

AuthorizationService::requirePasswordChanged($user);
AuthorizationService::requirePermission($user, 'ticket.view.all');

Http::json(['ok' => true]);
```

## 10. Limpieza futura de sesiones

Las sesiones expiradas permanecen como trazabilidad. Más adelante puede añadirse una tarea periódica para eliminar o archivar sesiones antiguas, sin que sea requisito para validar este bloque.
