# Plataforma de Mantenimiento â€” EU Tools

MÃ³dulo de negocio de mantenimiento dentro de **EU Tools**.

## Responsabilidades

Maintenance administra exclusivamente lÃ³gica del dominio de mantenimiento:

- tickets y evidencias;
- ubicaciones y supervisores;
- transiciones de estado;
- cotizaciones;
- solicitudes y decisiones de autorizaciÃ³n;
- notificaciones operativas;
- permisos aplicados a acciones del dominio.

La identidad pertenece a **Core**. Maintenance no crea, autentica, recupera, modifica ni administra cuentas de usuario.

## Identidad y sesiÃ³n

Core es responsable de:

- login y logout;
- usuarios y cuentas;
- sesiones persistentes;
- recuperaciÃ³n y cambio de contraseÃ±a;
- registro y verificaciÃ³n;
- invitaciones;
- acceso global a aplicaciones.

Maintenance comparte la sesiÃ³n de EU Tools mediante:

```dotenv
SESSION_NAME=EUTOOLSSESSID
SESSION_COOKIE_PATH=/
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=Lax
```

Estos valores deben permanecer compatibles con Core.

El frontend de Maintenance consume los endpoints centrales:

```text
GET  /api/auth/csrf
GET  /api/auth/me
POST /api/auth/logout
```

No existen endpoints locales de identidad en Maintenance.

## Persistencia

Maintenance y Core utilizan la misma base fÃ­sica:

```dotenv
DB_NAME=u170017077_tools
```

Maintenance consume identificadores de usuario y autorizaciÃ³n de plataforma, pero no es propietario del ciclo de vida de identidad.

La validaciÃ³n y renovaciÃ³n de `user_sessions` pertenece a Core. El contexto de Maintenance consume una sesiÃ³n Core ya validada y aplica acceso, rol y permisos de la aplicaciÃ³n `maintenance`.

## ConfiguraciÃ³n

Copiar:

```bash
cp .env.example .env
```

El archivo `.env` real no debe versionarse.

Variables operativas principales:

```dotenv
APP_URL=https://tools.estrategiaurbana.info/maintenance
DB_NAME=u170017077_tools
SESSION_NAME=EUTOOLSSESSID
SESSION_COOKIE_PATH=/
```

Las credenciales de base de datos y SMTP deben configurarse Ãºnicamente en el entorno real.

## Estructura

```text
maintenance/
â”œâ”€â”€ app/          # Servicios y reglas de negocio
â”œâ”€â”€ bootstrap/    # ComposiciÃ³n del mÃ³dulo
â”œâ”€â”€ public/       # UI y API operativa
â”œâ”€â”€ scripts/      # Utilidades/migraciones histÃ³ricas
â”œâ”€â”€ sql/          # SQL histÃ³rico y de soporte
â””â”€â”€ storage/      # Logs/uploads no pÃºblicos
```

Las capas privadas no deben exponerse desde web.

## Frontera de autorizaciÃ³n

Cada endpoint operativo debe obtener al usuario mediante `maintenance_context` y validar los permisos requeridos antes de ejecutar cambios de negocio.

El frontend utiliza `/api/auth/me` de Core para obtener la identidad y adapta la entrada de `applications` correspondiente a `maintenance` al formato de permisos que necesita la interfaz.

## Seguridad

- HTTPS obligatorio en producciÃ³n.
- Cookie compartida `EUTOOLSSESSID`, `Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/`.
- Los POST operativos requieren CSRF.
- El token CSRF se obtiene desde `/api/auth/csrf`.
- Un usuario sin acceso a `maintenance` debe recibir `application_access_denied`.
- El logout se realiza exclusivamente a travÃ©s de Core.
