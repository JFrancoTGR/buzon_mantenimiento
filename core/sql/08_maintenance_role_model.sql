-- EU Tools Core / Maintenance
-- Migration 08: Maintenance role model V2
-- Database: u170017077_tools
-- Target DB: MariaDB 11.8.x
--
-- Purpose:
--   Align the Maintenance authorization model and operational domain with the
--   final V2 profile vocabulary:
--
--     Reporter      -> territorial; creates and follows own reports
--     Operator      -> territorial; manages the operational ticket lifecycle
--     Approver      -> territorial; approves/rejects/requests quotation changes
--     Director      -> territorial; read-only visibility for granted locations
--     Administrator -> GLOBAL; platform administration and global visibility
--
-- Role-ID preservation strategy:
--   * reporter      keeps its current role_id
--   * supervisor    becomes operator and keeps its current role_id
--   * director      keeps its current role_id, but becomes territorial read-only
--   * administrator keeps its current role_id and GLOBAL semantics
--   * observer      becomes approver and keeps its current role_id
--
-- Because maintenance_access_grants references role_id, existing grants do not
-- need to be recreated. Their semantic profile changes with the preserved role.
-- In particular, existing Observer grants (for example Patricio Rodriguez)
-- become Approver grants in the same locations.
--
-- IMPORTANT DEPLOYMENT NOTE:
--   This is a CUTOVER migration. It renames physical columns and permission
--   codes consumed by Maintenance. Execute it only in the same release window
--   as the V2-aligned application code.
--
-- Historical migrations 01-07 remain unchanged.

USE `u170017077_tools`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @app_maintenance := (
    SELECT `id`
    FROM `applications`
    WHERE `code` = 'maintenance'
    LIMIT 1
);

SET @role_reporter := (
    SELECT `id`
    FROM `application_roles`
    WHERE `application_id` = @app_maintenance
      AND `code` = 'reporter'
    LIMIT 1
);

SET @role_supervisor := (
    SELECT `id`
    FROM `application_roles`
    WHERE `application_id` = @app_maintenance
      AND `code` = 'supervisor'
    LIMIT 1
);

SET @role_director := (
    SELECT `id`
    FROM `application_roles`
    WHERE `application_id` = @app_maintenance
      AND `code` = 'director'
    LIMIT 1
);

SET @role_administrator := (
    SELECT `id`
    FROM `application_roles`
    WHERE `application_id` = @app_maintenance
      AND `code` = 'administrator'
    LIMIT 1
);

SET @role_observer := (
    SELECT `id`
    FROM `application_roles`
    WHERE `application_id` = @app_maintenance
      AND `code` = 'observer'
    LIMIT 1
);

-- =========================================================
-- 0. Preflight snapshot
-- =========================================================
-- Expected before first execution:
--   migration_08_already_registered = 0
--   reporter/supervisor/director/administrator/observer role IDs are non-NULL
--
-- This migration intentionally performs one-time physical column renames and
-- should not be re-executed after version 08 is registered.

SELECT
    EXISTS(
        SELECT 1
        FROM `schema_migrations`
        WHERE `version` = '08'
    ) AS `migration_08_already_registered`,
    @app_maintenance AS `maintenance_app_id`,
    @role_reporter AS `reporter_role_id`,
    @role_supervisor AS `supervisor_role_id`,
    @role_director AS `director_role_id`,
    @role_observer AS `observer_role_id`,
    @role_administrator AS `administrator_role_id`;

-- =========================================================
-- 1. Domain terminology: Supervisor -> Operator
-- =========================================================

ALTER TABLE `locations`
    DROP FOREIGN KEY `fk_locations_default_supervisor_user`;

ALTER TABLE `locations`
    DROP INDEX `idx_locations_default_supervisor`,
    CHANGE COLUMN `default_supervisor_user_id`
        `default_operator_user_id` BIGINT UNSIGNED NULL;

ALTER TABLE `locations`
    ADD KEY `idx_locations_default_operator` (`default_operator_user_id`),
    ADD CONSTRAINT `fk_locations_default_operator_user`
        FOREIGN KEY (`default_operator_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL;

ALTER TABLE `tickets`
    DROP FOREIGN KEY `fk_tickets_supervisor`,
    DROP FOREIGN KEY `fk_tickets_director`;

ALTER TABLE `tickets`
    DROP INDEX `idx_tickets_supervisor_status`,
    DROP INDEX `idx_tickets_director_status`,
    CHANGE COLUMN `supervisor_user_id`
        `operator_user_id` BIGINT UNSIGNED NULL,
    CHANGE COLUMN `director_user_id`
        `approver_user_id` BIGINT UNSIGNED NULL;

ALTER TABLE `tickets`
    ADD KEY `idx_tickets_operator_status`
        (`operator_user_id`, `current_status_id`, `created_at`),
    ADD KEY `idx_tickets_approver_status`
        (`approver_user_id`, `current_status_id`, `created_at`),
    ADD CONSTRAINT `fk_tickets_operator`
        FOREIGN KEY (`operator_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    ADD CONSTRAINT `fk_tickets_approver`
        FOREIGN KEY (`approver_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL;

-- Preserve historical assignment rows if this migration is ever applied to a
-- database that already contains tickets. Expand the enum first, translate the
-- values, then contract it to the V2 vocabulary.

ALTER TABLE `ticket_assignment_history`
    MODIFY COLUMN `assignment_type`
        ENUM('supervisor','director','operator','approver','action_owner')
        NOT NULL;

UPDATE `ticket_assignment_history`
SET `assignment_type` = CASE
    WHEN `assignment_type` = 'supervisor' THEN 'operator'
    WHEN `assignment_type` = 'director' THEN 'approver'
    ELSE `assignment_type`
END
WHERE `assignment_type` IN ('supervisor', 'director');

ALTER TABLE `ticket_assignment_history`
    MODIFY COLUMN `assignment_type`
        ENUM('operator','approver','action_owner')
        NOT NULL;

ALTER TABLE `ticket_status_transitions`
    CHANGE COLUMN `requires_supervisor`
        `requires_operator` TINYINT(1) NOT NULL DEFAULT 0,
    CHANGE COLUMN `requires_director`
        `requires_approver` TINYINT(1) NOT NULL DEFAULT 0;

-- Lifecycle groups describe process phases rather than user roles.
UPDATE `ticket_statuses`
SET `lifecycle_group` = CASE
    WHEN `lifecycle_group` = 'supervisor' THEN 'operator'
    WHEN `lifecycle_group` = 'direction' THEN 'approval'
    ELSE `lifecycle_group`
END
WHERE `lifecycle_group` IN ('supervisor', 'direction');

-- =========================================================
-- 2. V2 application role catalog
-- =========================================================
-- Preserve role IDs so active and historical grants remain valid.

UPDATE `application_roles`
SET
    `code` = 'reporter',
    `name` = 'Reporter',
    `description` = 'Origina reportes de mantenimiento y consulta sus propios tickets dentro de las ubicaciones autorizadas.',
    `is_active` = 1
WHERE `id` = @role_reporter
  AND `application_id` = @app_maintenance;

UPDATE `application_roles`
SET
    `code` = 'operator',
    `name` = 'Operator',
    `description` = 'Conduce la operación del ticket, cotizaciones, escalamiento a autorización y ejecución dentro de las ubicaciones autorizadas.',
    `is_active` = 1
WHERE `id` = @role_supervisor
  AND `application_id` = @app_maintenance;

UPDATE `application_roles`
SET
    `code` = 'approver',
    `name` = 'Approver',
    `description` = 'Resuelve solicitudes de autorización asignadas dentro de las ubicaciones autorizadas.',
    `is_active` = 1
WHERE `id` = @role_observer
  AND `application_id` = @app_maintenance;

UPDATE `application_roles`
SET
    `code` = 'director',
    `name` = 'Director',
    `description` = 'Consulta en modo lectura todos los tickets y su trazabilidad dentro de las ubicaciones autorizadas.',
    `is_active` = 1
WHERE `id` = @role_director
  AND `application_id` = @app_maintenance;

UPDATE `application_roles`
SET
    `code` = 'administrator',
    `name` = 'Administrator',
    `description` = 'Administra configuración, seguridad y visibilidad global del módulo de Mantenimiento.',
    `is_active` = 1
WHERE `id` = @role_administrator
  AND `application_id` = @app_maintenance;

-- Aliases after the semantic rename.
SET @role_operator := @role_supervisor;
SET @role_approver := @role_observer;

-- =========================================================
-- 3. Permission terminology
-- =========================================================
-- Keep permission IDs stable. Only semantic codes/names/descriptions change.

UPDATE `permissions`
SET
    `code` = 'ticket.assign.operator',
    `name` = 'Asignar operador',
    `description` = 'Permite asignar o reasignar al operador responsable del ticket.'
WHERE `application_id` = @app_maintenance
  AND `code` = 'ticket.assign.supervisor';

UPDATE `permissions`
SET
    `code` = 'ticket.assign.approver',
    `name` = 'Asignar aprobador',
    `description` = 'Permite asignar al responsable de resolver una solicitud de autorización.'
WHERE `application_id` = @app_maintenance
  AND `code` = 'ticket.assign.director';

UPDATE `permissions`
SET
    `name` = 'Solicitar autorización',
    `description` = 'Permite enviar el ticket al aprobador asignado para decisión.'
WHERE `application_id` = @app_maintenance
  AND `code` = 'ticket.request_authorization';

UPDATE `permissions`
SET
    `name` = 'Solicitar cambios',
    `description` = 'Permite devolver una solicitud al operador para ajustes.'
WHERE `application_id` = @app_maintenance
  AND `code` = 'ticket.request_changes';

UPDATE `permissions`
SET
    `name` = 'Ver tickets de ubicación',
    `description` = 'Permite consultar todos los tickets dentro de las ubicaciones autorizadas por grants.'
WHERE `application_id` = @app_maintenance
  AND `code` = 'ticket.view.location';

-- =========================================================
-- 4. Exact Maintenance V2 role-permission matrix
-- =========================================================
--
-- Reporter (territorial)
--   access
--   ticket.create
--   ticket.view.own
--   ticket.comment
--   ticket.upload.evidence
--
-- Operator (territorial)
--   access
--   ticket.create
--   ticket.view.own
--   ticket.view.assigned
--   ticket.comment
--   ticket.assign.approver
--   ticket.change_status
--   ticket.upload.evidence
--   ticket.upload.quotation
--   ticket.request_authorization
--
-- Approver (territorial)
--   access
--   ticket.view.assigned
--   ticket.comment
--   ticket.authorize
--   ticket.reject
--   ticket.request_changes
--
-- Director (territorial)
--   access
--   ticket.view.location
--
-- Administrator (GLOBAL)
--   access
--   ticket.view.all
--   ticket.export
--   user.manage
--   role.manage
--   catalog.manage
--   audit.view

DELETE rp
FROM `role_permissions` rp
INNER JOIN `application_roles` r
    ON r.`id` = rp.`role_id`
   AND r.`application_id` = rp.`application_id`
WHERE rp.`application_id` = @app_maintenance
  AND r.`code` IN (
      'reporter',
      'operator',
      'approver',
      'director',
      'administrator'
  );

INSERT INTO `role_permissions` (
    `application_id`,
    `role_id`,
    `permission_id`
)
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
          r.`code` = 'operator'
          AND p.`code` IN (
              'access',
              'ticket.create',
              'ticket.view.own',
              'ticket.view.assigned',
              'ticket.comment',
              'ticket.assign.approver',
              'ticket.change_status',
              'ticket.upload.evidence',
              'ticket.upload.quotation',
              'ticket.request_authorization'
          )
      )
      OR
      (
          r.`code` = 'approver'
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
          r.`code` = 'director'
          AND p.`code` IN (
              'access',
              'ticket.view.location'
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
  );

-- =========================================================
-- 5. Register migration
-- =========================================================

INSERT INTO `schema_migrations` (`version`, `name`)
VALUES ('08', 'maintenance_role_model_v2')
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);

-- =========================================================
-- 6. Validation
-- =========================================================

-- 6.1 Migration registration. Expected: one row.
SELECT
    `version`,
    `name`,
    `applied_at`
FROM `schema_migrations`
WHERE `version` = '08';

-- 6.2 Exact V2 role catalog and permission counts.
-- Expected counts:
--   reporter      5
--   operator     10
--   approver      6
--   director      2
--   administrator 7
SELECT
    r.`id` AS `role_id`,
    r.`code` AS `role_code`,
    r.`name` AS `role_name`,
    r.`is_active`,
    COUNT(rp.`permission_id`) AS `permission_count`
FROM `application_roles` r
LEFT JOIN `role_permissions` rp
    ON rp.`role_id` = r.`id`
   AND rp.`application_id` = r.`application_id`
WHERE r.`application_id` = @app_maintenance
GROUP BY
    r.`id`,
    r.`code`,
    r.`name`,
    r.`is_active`
ORDER BY r.`id`;

-- 6.3 Permission matrix details.
SELECT
    r.`code` AS `role_code`,
    p.`code` AS `permission_code`
FROM `role_permissions` rp
INNER JOIN `application_roles` r
    ON r.`id` = rp.`role_id`
   AND r.`application_id` = rp.`application_id`
INNER JOIN `permissions` p
    ON p.`id` = rp.`permission_id`
   AND p.`application_id` = rp.`application_id`
WHERE rp.`application_id` = @app_maintenance
ORDER BY r.`code`, p.`code`;

-- 6.4 Legacy role/permission codes. Expected: all zeros.
SELECT
    (SELECT COUNT(*)
     FROM `application_roles`
     WHERE `application_id` = @app_maintenance
       AND `code` IN ('supervisor', 'observer')) AS `legacy_role_codes`,
    (SELECT COUNT(*)
     FROM `permissions`
     WHERE `application_id` = @app_maintenance
       AND `code` IN ('ticket.assign.supervisor', 'ticket.assign.director')) AS `legacy_permission_codes`;

-- 6.5 Physical schema vocabulary. Expected new columns = 6, legacy columns = 0.
SELECT
    SUM(`column_name` IN (
        'default_operator_user_id',
        'operator_user_id',
        'approver_user_id',
        'requires_operator',
        'requires_approver',
        'assignment_type'
    )) AS `v2_columns_found`,
    SUM(`column_name` IN (
        'default_supervisor_user_id',
        'supervisor_user_id',
        'director_user_id',
        'requires_supervisor',
        'requires_director'
    )) AS `legacy_columns_found`
FROM `information_schema`.`columns`
WHERE `table_schema` = DATABASE()
  AND `table_name` IN (
      'locations',
      'tickets',
      'ticket_assignment_history',
      'ticket_status_transitions'
  );

-- 6.6 Assignment type enum. Expected:
-- enum('operator','approver','action_owner')
SELECT
    `table_name`,
    `column_name`,
    `column_type`
FROM `information_schema`.`columns`
WHERE `table_schema` = DATABASE()
  AND `table_name` = 'ticket_assignment_history'
  AND `column_name` = 'assignment_type';

-- 6.7 Lifecycle groups. Expected no supervisor/direction groups.
SELECT
    `lifecycle_group`,
    COUNT(*) AS `status_count`
FROM `ticket_statuses`
GROUP BY `lifecycle_group`
ORDER BY `lifecycle_group`;

-- 6.8 Active grants after semantic role migration.
-- Existing role IDs are preserved, so no grant recreation is expected.
SELECT
    u.`id` AS `user_id`,
    u.`email`,
    r.`code` AS `role_code`,
    COALESCE(l.`code`, 'GLOBAL') AS `scope`,
    mag.`granted_at`
FROM `maintenance_access_grants` mag
INNER JOIN `users` u
    ON u.`id` = mag.`user_id`
INNER JOIN `application_roles` r
    ON r.`id` = mag.`role_id`
   AND r.`application_id` = mag.`application_id`
LEFT JOIN `locations` l
    ON l.`id` = mag.`location_id`
WHERE mag.`application_id` = @app_maintenance
  AND mag.`revoked_at` IS NULL
ORDER BY u.`id`, COALESCE(l.`sort_order`, 0), l.`id`;

-- 6.9 GLOBAL invariant. Expected: 0 rows.
-- Director remains territorial in V2; Administrator is the only GLOBAL role.
SELECT
    mag.`id` AS `grant_id`,
    u.`email`,
    r.`code` AS `role_code`
FROM `maintenance_access_grants` mag
INNER JOIN `users` u
    ON u.`id` = mag.`user_id`
INNER JOIN `application_roles` r
    ON r.`id` = mag.`role_id`
   AND r.`application_id` = mag.`application_id`
WHERE mag.`application_id` = @app_maintenance
  AND mag.`revoked_at` IS NULL
  AND mag.`location_id` IS NULL
  AND r.`code` <> 'administrator';

-- 6.10 Patricio migration check.
-- Expected on the current production dataset: Approver in his existing locations.
SELECT
    u.`id` AS `user_id`,
    u.`email`,
    r.`code` AS `role_code`,
    l.`code` AS `location_code`,
    l.`name` AS `location_name`
FROM `maintenance_access_grants` mag
INNER JOIN `users` u
    ON u.`id` = mag.`user_id`
INNER JOIN `application_roles` r
    ON r.`id` = mag.`role_id`
   AND r.`application_id` = mag.`application_id`
LEFT JOIN `locations` l
    ON l.`id` = mag.`location_id`
WHERE mag.`application_id` = @app_maintenance
  AND mag.`revoked_at` IS NULL
  AND u.`email` = 'prodriguez@estrategiaurbana.com.mx'
ORDER BY l.`sort_order`, l.`id`;

-- 6.11 Default Operators preserved by column rename.
SELECT
    l.`id`,
    l.`code`,
    l.`name`,
    l.`default_operator_user_id`,
    u.`email` AS `default_operator_email`
FROM `locations` l
LEFT JOIN `users` u
    ON u.`id` = l.`default_operator_user_id`
ORDER BY l.`sort_order`, l.`id`;
