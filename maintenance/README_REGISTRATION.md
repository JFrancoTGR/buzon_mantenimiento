# Registro público y verificación de correo

## Alcance

Este parche incorpora:

- `register.html`.
- Cuenta pública con estado `pending`.
- Rol automático `reporter`.
- Verificación por enlace de un solo uso.
- Conservación de `location` proveniente del QR.
- Reenvío controlado del enlace.
- Inicio automático de sesión después de verificar.
- Redirección a `new-ticket.html?location=...`.
- Registro de envíos en `email_log`.
- Rate limit básico por IP y por usuario.
- Honeypot contra bots.

## 1. Enlace que debe agregarse manualmente a login.html

```html
<p class="auth-switch">
  ¿No tienes una cuenta?
  <a id="register-link" href="./register.html">Crear cuenta</a>
</p>
```

No es necesario añadir lógica adicional. `login.js` conservará automáticamente el parámetro `location`.

## 2. Instalar PHPMailer

Desde la raíz `public_html/mantenimiento`:

```bash
composer install --no-dev --optimize-autoloader
```

## 3. Agregar variables al .env real

```dotenv
EMAIL_VERIFICATION_TTL_MINUTES=60
REGISTRATION_MAX_PER_IP_HOUR=5
VERIFICATION_RESEND_COOLDOWN_MINUTES=10
VERIFICATION_RESEND_MAX_PER_HOUR=3

MAIL_TRANSPORT=smtp
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_AUTH=true
SMTP_USERNAME=notificaciones@example.com
SMTP_PASSWORD="contraseña_real"
SMTP_ENCRYPTION=tls
SMTP_TIMEOUT_SECONDS=15
SMTP_FROM_ADDRESS=notificaciones@example.com
SMTP_FROM_NAME="Plataforma de Mantenimiento"
```

Para una prueba sin enviar correos reales:

```dotenv
MAIL_TRANSPORT=log
SMTP_FROM_ADDRESS=notificaciones@estrategiaurbana.info
SMTP_FROM_NAME="Plataforma de Mantenimiento"
```

El enlace quedará en `storage/logs/mail.log`. No debe usarse `log` en producción.

## 4. Validaciones

```bash
php scripts/check_registration.php
php scripts/send_test_email.php correo-de-prueba@dominio.com
```

## 5. URLs QR

```text
https://estrategiaurbana.info/mantenimiento/login.html?location=showroom_naos
https://estrategiaurbana.info/mantenimiento/login.html?location=showroom_the_wavve
https://estrategiaurbana.info/mantenimiento/login.html?location=showroom_estrategia_urbana
https://estrategiaurbana.info/mantenimiento/login.html?location=corporativo_estrategia_urbana
```

## 6. Flujo de prueba

1. Abrir una URL QR.
2. Seleccionar `Crear cuenta`.
3. Confirmar que la ubicación aparezca en registro.
4. Crear cuenta.
5. Abrir el enlace recibido.
6. Confirmar el SweetAlert `Correo verificado`.
7. Validar que la URL final conserve `?location=...`.
8. Confirmar en MariaDB:

```sql
SELECT id, email, status, email_verified_at, must_change_password
FROM users
ORDER BY id DESC
LIMIT 5;

SELECT user_id, location_id, expires_at, used_at, revoked_at, created_at
FROM email_verification_tokens
ORDER BY id DESC
LIMIT 10;

SELECT recipient_email, event_code, status, error_message, sent_at, created_at
FROM email_log
ORDER BY id DESC
LIMIT 10;
```

`new-ticket.html` es un destino temporal de validación y será reemplazado por el formulario real en la siguiente fase.
