-- EU Tools Core
-- Validation: Maintenance observer role
-- Database: u170017077_tools
--
-- Safe read-only verification after running:
--   05_maintenance_observer_role.sql

USE `u170017077_tools`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @app_maintenance := (
    SELECT `id`
    FROM `applications`
    WHERE `code` = 'maintenance'
    LIMIT 1
);

SET @observer_role := (
    SELECT `id`
    FROM `application_roles`
    WHERE `application_id` = @app_maintenance
      AND `code` = 'observer'
    LIMIT 1
);

-- A. Role must exist and be active.
SELECT
    a.`code` AS `application_code`,
    r.`code` AS `role_code`,
    r.`name` AS `role_name`,
    r.`description`,
    r.`is_system`,
    r.`is_active`
FROM `application_roles` r
INNER JOIN `applications` a
    ON a.`id` = r.`application_id`
WHERE a.`code` = 'maintenance'
  AND r.`code` = 'observer';

-- B. Observer must have exactly two permissions.
SELECT
    COUNT(*) AS `permission_count`
FROM `role_permissions` rp
WHERE rp.`application_id` = @app_maintenance
  AND rp.`role_id` = @observer_role;

-- C. Those permissions must be exactly access + ticket.view.all.
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

-- D. This query must return zero rows.
SELECT
    p.`code` AS `unexpected_permission`
FROM `role_permissions` rp
INNER JOIN `permissions` p
    ON p.`id` = rp.`permission_id`
   AND p.`application_id` = rp.`application_id`
WHERE rp.`application_id` = @app_maintenance
  AND rp.`role_id` = @observer_role
  AND p.`code` NOT IN ('access', 'ticket.view.all')
ORDER BY p.`code`;

-- E. Migration must be registered.
SELECT
    `version`,
    `name`,
    `applied_at`
FROM `schema_migrations`
WHERE `version` = '05';
