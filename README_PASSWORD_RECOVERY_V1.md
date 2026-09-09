# Password Recovery V1

Módulo de recuperación de contraseña para usuarios no autenticados.

## Flujo

```text
login.html
  ↓
¿Olvidaste tu contraseña?
  ↓
forgot-password.html
  ↓
POST /api/auth/forgot-password.php
  ↓
respuesta pública genérica
  ↓
correo con reset-password.html#token=...
  ↓
POST /api/auth/password-reset-context.php
  ↓
nueva contraseña + confirmación
  ↓
POST /api/auth/reset-password.php
  ↓
password_hash actualizado
tokens restantes revocados
sesiones revocadas
correo de seguridad
  ↓
login.html
```

## Reglas de seguridad

- El endpoint no revela si el correo existe o el estado de la cuenta.
- Sólo cuentas `active`, verificadas y con contraseña pueden recuperar acceso.
- `pending`, `invited`, `inactive` y `blocked` no obtienen un reset utilizable.
- Token aleatorio de 32 bytes; sólo SHA-256 se guarda en BDD.
- Token transportado en fragmento `#token=...`, no en query string.
- Vigencia predeterminada: 30 minutos.
- Un solo uso.
- Nueva contraseña debe cumplir la política existente y no puede ser igual a la actual.
- Al completar el reset se revocan todas las sesiones y los demás reset tokens.
- No hay autologin.
- El correo final de seguridad se envía después del COMMIT.
- Fallos SMTP no revierten cambios de negocio.
- En reenvío, el token anterior sólo se revoca después de entregar correctamente el nuevo.
- Emisión serializada por usuario para evitar carreras entre solicitudes concurrentes.
- Rate limit por IP usa `audit_log`, por lo que también cuenta solicitudes a correos inexistentes.

## Defaults

No es obligatorio modificar `.env`; el servicio usa:

```dotenv
PASSWORD_RESET_TTL_MINUTES=30
PASSWORD_RESET_COOLDOWN_MINUTES=10
PASSWORD_RESET_MAX_PER_USER_HOUR=3
PASSWORD_RESET_MAX_PER_IP_HOUR=10
```

Pueden declararse explícitamente si se desea modificar esos valores.

## Dependencias

- Migración 16 aplicada y validada.
- Account Resilience Patch V1 aplicado.
- Componente `passwordVisibility.js` presente.

## Instalación

Extraer el ZIP sobre la raíz de `/mantenimiento` y ejecutar:

```bash
php scripts/apply_password_recovery_module.php
php scripts/check_password_recovery_module.php
```

Después limpiar CDN/cache antes del QA web.

El integrador crea backups:

```text
*.before-password-recovery.bak
```

de los archivos existentes que modifica.

## QA

El QA funcional general se realizará después de instalar este módulo, cubriendo registro, verificación, invitación, login, perfil, cambios de credenciales, recuperación, revocaciones y resiliencia SMTP.
