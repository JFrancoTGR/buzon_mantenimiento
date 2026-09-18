# Formulario de nuevo reporte — V1

Este bloque sustituye la pantalla temporal `new-ticket.html` por el formulario funcional de alta de tickets.

## Requisitos previos

La base debe tener aplicadas:

- Migración `09`: `locations.default_supervisor_user_id`.
- Migración `10`: `tickets.specific_location`.
- Las cuatro ubicaciones activas con un supervisor activo que tenga rol `administrator` o `supervisor`.

## Instalación

Extraer el parche en la raíz del proyecto:

```text
public_html/mantenimiento/
```

No se reemplaza `.env`.

Asegurar que PHP pueda escribir en:

```text
storage/uploads
```

Cuando sea necesario:

```bash
chmod -R 770 storage/uploads
```

## Validación CLI

```bash
php scripts/check_ticket_form.php
```

El script revisa:

- Extensiones `pdo_mysql`, `fileinfo` y `hash`.
- Tablas operativas.
- Columnas creadas en migraciones 09 y 10.
- Permiso `ticket.create`.
- Ubicaciones y supervisores activos.
- Prioridades y estado `new`.
- Escritura en `storage/uploads`.
- Límites PHP de carga.

## Límites de carga

El catálogo `system_settings` define inicialmente:

```text
upload.evidence.max_files = 8
upload.evidence.max_size_mb = 8
```

PHP debe permitir un tamaño total compatible. Para ocho imágenes de 8 MB, `post_max_size` debe ser superior a 64 MB y `max_file_uploads` debe ser al menos 8.

## Flujo implementado

1. Valida sesión, cambio obligatorio de contraseña y permiso `ticket.create`.
2. Carga reportante, ubicaciones, prioridades y límites.
3. Conserva y bloquea la sede cuando proviene de `?location=...`.
4. Solicita zona específica, título, descripción, prioridad y evidencias.
5. Valida JPG/JPEG/PNG por MIME real, tamaño y dimensiones.
6. Reserva el folio dentro de una transacción.
7. Crea el ticket con estado `new`.
8. Asigna supervisor y responsable de acción desde `locations`.
9. Registra estado inicial, asignaciones, archivos, auditoría y notificación.
10. Confirma la transacción.
11. Envía correo al reportante y al supervisor.
12. Muestra el folio con SweetAlert y redirige al dashboard.

## Archivos almacenados

Las evidencias se guardan fuera de `public`:

```text
storage/uploads/tickets/YYYY/MM/{ticket_id}/
```

MariaDB conserva únicamente metadatos en `ticket_attachments`.

## Endpoints

```text
GET  /api/tickets/context.php
POST /api/tickets/create.php
```

El `POST` utiliza `multipart/form-data` y el encabezado `X-CSRF-Token`.

## Prueba funcional recomendada

Abrir como usuario reportante verificado:

```text
https://estrategiaurbana.info/mantenimiento/new-ticket.html?location=showroom_naos
```

Crear un reporte con una fotografía y comprobar:

```sql
SELECT * FROM tickets ORDER BY id DESC LIMIT 1;
SELECT * FROM ticket_status_history ORDER BY id DESC LIMIT 5;
SELECT * FROM ticket_assignment_history ORDER BY id DESC LIMIT 5;
SELECT * FROM ticket_attachments ORDER BY id DESC LIMIT 5;
SELECT * FROM notifications ORDER BY id DESC LIMIT 5;
SELECT * FROM email_log ORDER BY id DESC LIMIT 5;
SELECT * FROM audit_log ORDER BY id DESC LIMIT 5;
SELECT * FROM folio_sequences WHERE sequence_code = 'MNT';
```
