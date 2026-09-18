-- EU Tools Core
-- Migration 01: initial applications, roles, permissions and role matrix
-- Database: u170017077_tools
--
-- Code/slugs are kept in English.
-- User-facing names remain Spanish for the current UI.

USE `u170017077_tools`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- =========================================================
-- 1. Applications
-- =========================================================

INSERT INTO `applications` (
    `code`, `name`, `description`, `base_path`, `show_in_hub`, `sort_order`, `is_active`
) VALUES
    ('core', 'Core Tools', 'Identidad, cuenta, sesiones y autorización central de EU Tools.', '/', 0, 0, 1),
    ('maintenance', 'Mantenimiento', 'Gestión de reportes, cotizaciones, autorizaciones y ejecución de mantenimiento.', '/maintenance', 1, 10, 1)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `base_path` = VALUES(`base_path`),
    `show_in_hub` = VALUES(`show_in_hub`),
    `sort_order` = VALUES(`sort_order`),
    `is_active` = VALUES(`is_active`);

SET @app_core := (
    SELECT `id` FROM `applications` WHERE `code` = 'core' LIMIT 1
);
SET @app_maintenance := (
    SELECT `id` FROM `applications` WHERE `code` = 'maintenance' LIMIT 1
);

-- =========================================================
-- 2. Application roles
-- =========================================================

INSERT INTO `application_roles` (
    `application_id`, `code`, `name`, `description`, `is_system`, `is_active`
) VALUES
    (@app_core, 'system_administrator', 'Administrador del sistema', 'Gobierna identidades, aplicaciones, accesos y seguridad de EU Tools.', 1, 1),

    (@app_maintenance, 'reporter', 'Usuario reportante', 'Origina y consulta sus propios reportes de mantenimiento.', 1, 1),
    (@app_maintenance, 'supervisor', 'Supervisor', 'Conduce la operación del mantenimiento, cotizaciones, autorizaciones y ejecución.', 1, 1),
    (@app_maintenance, 'director', 'Dirección', 'Revisa y resuelve solicitudes de autorización que le sean asignadas.', 1, 1),
    (@app_maintenance, 'administrator', 'Administrador', 'Administra configuración y visibilidad global del módulo de Mantenimiento.', 1, 1)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `is_system` = VALUES(`is_system`),
    `is_active` = VALUES(`is_active`);

-- =========================================================
-- 3. Core permissions / scopes
-- =========================================================

INSERT INTO `permissions` (
    `application_id`, `code`, `module`, `name`, `description`
) VALUES
    (@app_core, 'access', 'core', 'Acceder a EU Tools', 'Permite utilizar el Core autenticado de EU Tools.'),
    (@app_core, 'user.manage', 'users', 'Gestionar usuarios', 'Permite crear, invitar, editar, activar o desactivar identidades globales.'),
    (@app_core, 'application.manage', 'applications', 'Gestionar herramientas', 'Permite administrar el catálogo y configuración de herramientas.'),
    (@app_core, 'access.manage', 'security', 'Gestionar accesos', 'Permite asignar, cambiar o revocar roles de usuarios por herramienta.'),
    (@app_core, 'audit.view', 'audit', 'Consultar auditoría del Core', 'Permite consultar la bitácora de identidad y autorización del Core.')
ON DUPLICATE KEY UPDATE
    `module` = VALUES(`module`),
    `name` = VALUES(`name`),
    `description` = VALUES(`description`);

-- =========================================================
-- 4. Maintenance permissions / scopes
--    The 19 proven V1 permissions are preserved verbatim,
--    plus a new application-level "access" scope.
-- =========================================================

INSERT INTO `permissions` (
    `application_id`, `code`, `module`, `name`, `description`
) VALUES
    (@app_maintenance, 'access', 'application', 'Acceder a Mantenimiento', 'Permite abrir y utilizar la herramienta de Mantenimiento.'),
    (@app_maintenance, 'ticket.create', 'tickets', 'Crear tickets', 'Permite crear reportes de mantenimiento.'),
    (@app_maintenance, 'ticket.view.own', 'tickets', 'Ver tickets propios', 'Permite consultar tickets creados por el usuario.'),
    (@app_maintenance, 'ticket.view.assigned', 'tickets', 'Ver tickets asignados', 'Permite consultar tickets asignados al usuario.'),
    (@app_maintenance, 'ticket.view.all', 'tickets', 'Ver todos los tickets', 'Permite consultar todos los tickets.'),
    (@app_maintenance, 'ticket.comment', 'tickets', 'Agregar comentarios', 'Permite agregar comentarios visibles en tickets.'),
    (@app_maintenance, 'ticket.assign.supervisor', 'tickets', 'Asignar supervisor', 'Permite asignar o reasignar un supervisor.'),
    (@app_maintenance, 'ticket.assign.director', 'tickets', 'Asignar Dirección', 'Permite asignar al responsable de autorización.'),
    (@app_maintenance, 'ticket.change_status', 'tickets', 'Cambiar estado', 'Permite ejecutar cambios generales de estado.'),
    (@app_maintenance, 'ticket.upload.evidence', 'attachments', 'Cargar evidencias', 'Permite adjuntar fotografías de evidencia.'),
    (@app_maintenance, 'ticket.upload.quotation', 'quotations', 'Cargar cotizaciones', 'Permite cargar y versionar cotizaciones.'),
    (@app_maintenance, 'ticket.request_authorization', 'authorizations', 'Solicitar autorización', 'Permite escalar el ticket a Dirección.'),
    (@app_maintenance, 'ticket.authorize', 'authorizations', 'Autorizar solicitud', 'Permite autorizar una solicitud.'),
    (@app_maintenance, 'ticket.reject', 'authorizations', 'Rechazar solicitud', 'Permite rechazar una solicitud.'),
    (@app_maintenance, 'ticket.request_changes', 'authorizations', 'Solicitar cambios', 'Permite devolver una solicitud al supervisor.'),
    (@app_maintenance, 'ticket.export', 'reports', 'Exportar tickets', 'Permite exportar CSV o Excel.'),
    (@app_maintenance, 'user.manage', 'users', 'Gestionar usuarios', 'Scope de compatibilidad V1 del módulo; la identidad global será administrada por Core.'),
    (@app_maintenance, 'role.manage', 'security', 'Gestionar roles y permisos', 'Scope de compatibilidad V1 del módulo; la autorización global será administrada por Core.'),
    (@app_maintenance, 'catalog.manage', 'catalogs', 'Gestionar catálogos', 'Permite administrar ubicaciones, estados y prioridades.'),
    (@app_maintenance, 'audit.view', 'audit', 'Consultar auditoría', 'Permite consultar la bitácora de auditoría de Mantenimiento.')
ON DUPLICATE KEY UPDATE
    `module` = VALUES(`module`),
    `name` = VALUES(`name`),
    `description` = VALUES(`description`);

-- =========================================================
-- 5. Core system administrator matrix
-- =========================================================

INSERT INTO `role_permissions` (`application_id`, `role_id`, `permission_id`)
SELECT
    @app_core,
    r.`id`,
    p.`id`
FROM `application_roles` r
INNER JOIN `permissions` p
    ON p.`application_id` = @app_core
WHERE r.`application_id` = @app_core
  AND r.`code` = 'system_administrator'
  AND p.`code` IN (
      'access',
      'user.manage',
      'application.manage',
      'access.manage',
      'audit.view'
  )
ON DUPLICATE KEY UPDATE
    `application_id` = VALUES(`application_id`);

-- =========================================================
-- 6. Maintenance V1 role matrix
-- =========================================================

INSERT INTO `role_permissions` (`application_id`, `role_id`, `permission_id`)
SELECT
    @app_maintenance,
    r.`id`,
    p.`id`
FROM `application_roles` r
INNER JOIN `permissions` p
    ON p.`application_id` = @app_maintenance
WHERE r.`application_id` = @app_maintenance
  AND (
      (
          r.`code` = 'reporter'
          AND p.`code` IN (
              'access',
              'ticket.create',
              'ticket.view.own',
              'ticket.comment',
              'ticket.upload.evidence'
          )
      )
      OR
      (
          r.`code` = 'supervisor'
          AND p.`code` IN (
              'access',
              'ticket.create',
              'ticket.view.own',
              'ticket.view.assigned',
              'ticket.comment',
              'ticket.assign.director',
              'ticket.change_status',
              'ticket.upload.evidence',
              'ticket.upload.quotation',
              'ticket.request_authorization'
          )
      )
      OR
      (
          r.`code` = 'director'
          AND p.`code` IN (
              'access',
              'ticket.view.assigned',
              'ticket.comment',
              'ticket.authorize',
              'ticket.reject',
              'ticket.request_changes'
          )
      )
      OR
      (
          r.`code` = 'administrator'
          AND p.`code` IN (
              'access',
              'ticket.view.all',
              'ticket.export',
              'user.manage',
              'role.manage',
              'catalog.manage',
              'audit.view'
          )
      )
  )
ON DUPLICATE KEY UPDATE
    `application_id` = VALUES(`application_id`);

-- =========================================================
-- 7. Register migration
-- =========================================================

INSERT INTO `schema_migrations` (`version`, `name`)
VALUES ('01', 'core_authorization_seed')
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);

-- =========================================================
-- VALIDATION
-- Expected baseline:
--   applications = 2
--   application_roles = 5
--   permissions = 25
--   role_permissions = 33
-- =========================================================

SELECT 'applications' AS `item`, COUNT(*) AS `total`
FROM `applications`
UNION ALL
SELECT 'application_roles', COUNT(*)
FROM `application_roles`
UNION ALL
SELECT 'permissions', COUNT(*)
FROM `permissions`
UNION ALL
SELECT 'role_permissions', COUNT(*)
FROM `role_permissions`;

SELECT
    a.`code` AS `application_code`,
    r.`code` AS `role_code`,
    COUNT(rp.`permission_id`) AS `permission_count`
FROM `application_roles` r
INNER JOIN `applications` a
    ON a.`id` = r.`application_id`
LEFT JOIN `role_permissions` rp
    ON rp.`role_id` = r.`id`
   AND rp.`application_id` = r.`application_id`
GROUP BY a.`code`, r.`code`
ORDER BY a.`code`, r.`code`;

SELECT
    a.`code` AS `application_code`,
    r.`code` AS `role_code`,
    p.`code` AS `permission_code`
FROM `role_permissions` rp
INNER JOIN `applications` a
    ON a.`id` = rp.`application_id`
INNER JOIN `application_roles` r
    ON r.`id` = rp.`role_id`
   AND r.`application_id` = rp.`application_id`
INNER JOIN `permissions` p
    ON p.`id` = rp.`permission_id`
   AND p.`application_id` = rp.`application_id`
ORDER BY a.`code`, r.`code`, p.`code`;

SELECT
    `version`,
    `name`,
    `applied_at`
FROM `schema_migrations`
ORDER BY `version`;
