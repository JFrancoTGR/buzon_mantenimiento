-- EU Tools Core
-- Migration 05: Maintenance observer role
-- Database: u170017077_tools
--
-- Purpose:
--   Add a global read-only Maintenance profile.
--   Observer can inspect every ticket and its complete internal lifecycle,
--   but cannot create, comment, transition, authorize, upload or administer.

USE `u170017077_tools`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @app_maintenance := (
    SELECT `id`
    FROM `applications`
    WHERE `code` = 'maintenance'
    LIMIT 1
);

-- =========================================================
-- 1. Observer application role
-- =========================================================

INSERT INTO `application_roles` (
    `application_id`,
    `code`,
    `name`,
    `description`,
    `is_system`,
    `is_active`
)
SELECT
    @app_maintenance,
    'observer',
    'Observador',
    'Consulta todos los tickets y su trazabilidad sin intervenir en el flujo operativo.',
    1,
    1
WHERE @app_maintenance IS NOT NULL
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `is_system` = VALUES(`is_system`),
    `is_active` = VALUES(`is_active`);

SET @observer_role := (
    SELECT `id`
    FROM `application_roles`
    WHERE `application_id` = @app_maintenance
      AND `code` = 'observer'
    LIMIT 1
);

-- =========================================================
-- 2. Exact Observer permission matrix
--
-- Intended capabilities:
--   access
--   ticket.view.all
--
-- Re-running this migration restores Observer to the intended
-- read-only baseline if extra permissions were added manually.
-- =========================================================

DELETE FROM `role_permissions`
WHERE `application_id` = @app_maintenance
  AND `role_id` = @observer_role;

INSERT INTO `role_permissions` (
    `application_id`,
    `role_id`,
    `permission_id`
)
SELECT
    @app_maintenance,
    @observer_role,
    p.`id`
FROM `permissions` p
WHERE p.`application_id` = @app_maintenance
  AND p.`code` IN (
      'access',
      'ticket.view.all'
  )
  AND @observer_role IS NOT NULL;

-- =========================================================
-- 3. Register migration
-- =========================================================

INSERT INTO `schema_migrations` (`version`, `name`)
SELECT '05', 'maintenance_observer_role'
WHERE @app_maintenance IS NOT NULL
  AND @observer_role IS NOT NULL
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);

-- =========================================================
-- Validation
-- Expected:
--   role_code         = observer
--   permission_count  = 2
--   permissions       = access, ticket.view.all
--   unexpected        = 0 rows
-- =========================================================

SELECT
    a.`code` AS `application_code`,
    r.`code` AS `role_code`,
    r.`name` AS `role_name`,
    r.`is_active`,
    COUNT(rp.`permission_id`) AS `permission_count`
FROM `application_roles` r
INNER JOIN `applications` a
    ON a.`id` = r.`application_id`
LEFT JOIN `role_permissions` rp
    ON rp.`role_id` = r.`id`
   AND rp.`application_id` = r.`application_id`
WHERE a.`code` = 'maintenance'
  AND r.`code` = 'observer'
GROUP BY
    a.`code`,
    r.`code`,
    r.`name`,
    r.`is_active`;

SELECT
    p.`code` AS `permission_code`,
    p.`name` AS `permission_name`
FROM `role_permissions` rp
INNER JOIN `permissions` p
    ON p.`id` = rp.`permission_id`
   AND p.`application_id` = rp.`application_id`
WHERE rp.`application_id` = @app_maintenance
  AND rp.`role_id` = @observer_role
ORDER BY p.`code`;

SELECT
    p.`code` AS `unexpected_permission`
FROM `role_permissions` rp
INNER JOIN `permissions` p
    ON p.`id` = rp.`permission_id`
   AND p.`application_id` = rp.`application_id`
WHERE rp.`application_id` = @app_maintenance
  AND rp.`role_id` = @observer_role
  AND p.`code` NOT IN (
      'access',
      'ticket.view.all'
  )
ORDER BY p.`code`;

SELECT
    `version`,
    `name`,
    `applied_at`
FROM `schema_migrations`
WHERE `version` = '05';
