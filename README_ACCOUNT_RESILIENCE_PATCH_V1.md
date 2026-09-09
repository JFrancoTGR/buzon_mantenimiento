# Account Resilience / Security UX Patch V1

Parche incremental previo al módulo de recuperación de contraseña.

## Incluye

- Notificación por correo después de actualizar nombre/apellidos.
- Notificación de seguridad después de cambiar contraseña.
- Los fallos SMTP de esas notificaciones no revierten la operación principal.
- Cambio de contraseña sigue revocando todas las sesiones.
- Registro público deja de depender de `MailerService::assertReady()` antes del COMMIT.
- Reenvíos de verificación e invitación preservan el enlace anterior hasta confirmar que el nuevo correo fue enviado.
- Si el envío nuevo falla, sólo se revoca el token recién creado.
- El directorio de Usuarios prioriza mostrar una invitación todavía utilizable sobre un intento de reenvío fallido/revocado.
- Componente reutilizable para mostrar/ocultar contraseñas sin modificar `autocomplete`.

## Instalación

1. Extraer el ZIP sobre la raíz de `/mantenimiento`.
2. Ejecutar:

```bash
php scripts/apply_account_resilience_patch.php
php scripts/check_account_resilience_patch.php
```

3. Limpiar CDN/cache de assets antes del QA de navegador.

El script de integración crea backups `*.before-account-resilience.bak` de los archivos que modifica.

## QA recomendado

- Perfil: cambio de nombre persiste y llega correo de notificación.
- Contraseña: cambia, revoca sesión, llega correo de seguridad y la nueva credencial funciona.
- Ojito: login, registro, invitación, cambio obligatorio y perfil; `autocomplete` sigue funcionando.
- Invitación: reenvío exitoso invalida enlace anterior; nuevo enlace funciona.
- Si se simula fallo SMTP durante reenvío, el enlace anterior debe continuar vigente.
- Registro/tickets/acciones de negocio no deben revertirse por fallo de correo.
