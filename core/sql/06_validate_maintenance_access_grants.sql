-- EU Tools Core / Maintenance
-- Validation 06: Maintenance access grants cutover
-- Read-only validation script.

-- Fully qualified against u170017077_tools so validation does not depend
-- on which database phpMyAdmin currently has selected.
USE `u170017077_tools`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @app_maintenance := (
    SELECT `id`
    FROM `u170017077_tools`.`applications`
    WHERE `code` = 'maintenance'
    LIMIT 1
);

-- A. Migration registered.
SELECT
    `version`,
    `name`,
    `applied_at`
FROM `u170017077_tools`.`schema_migrations`
WHERE `version` = '06';

-- B. Grant table structure summary.
SHOW CREATE TABLE `u170017077_tools`.`maintenance_access_grants`;

-- C. Observer matrix.
-- Expected permission_count = 2 and only access + ticket.view.location.
SELECT
    ar.`code` AS `role_code`,
    ar.`name` AS `role_name`,
    ar.`is_active`,
    COUNT(rp.`permission_id`) AS `permission_count`
FROM `u170017077_tools`.`application_roles` ar
LEFT JOIN `u170017077_tools`.`role_permissions` rp
    ON rp.`role_id` = ar.`id`
   AND rp.`application_id` = ar.`application_id`
WHERE ar.`application_id` = @app_maintenance
  AND ar.`code` = 'observer'
GROUP BY ar.`id`, ar.`code`, ar.`name`, ar.`is_active`;

SELECT
    p.`code` AS `permission_code`,
    p.`name` AS `permission_name`
FROM `u170017077_tools`.`role_permissions` rp
INNER JOIN `u170017077_tools`.`application_roles` ar
    ON ar.`id` = rp.`role_id`
   AND ar.`application_id` = rp.`application_id`
INNER JOIN `u170017077_tools`.`permissions` p
    ON p.`id` = rp.`permission_id`
   AND p.`application_id` = rp.`application_id`
WHERE rp.`application_id` = @app_maintenance
  AND ar.`code` = 'observer'
ORDER BY p.`code`;

-- D. Unexpected Observer permissions. Expected: zero rows.
SELECT
    p.`code` AS `unexpected_permission`
FROM `u170017077_tools`.`role_permissions` rp
INNER JOIN `u170017077_tools`.`application_roles` ar
    ON ar.`id` = rp.`role_id`
   AND ar.`application_id` = rp.`application_id`
INNER JOIN `u170017077_tools`.`permissions` p
    ON p.`id` = rp.`permission_id`
   AND p.`application_id` = rp.`application_id`
WHERE rp.`application_id` = @app_maintenance
  AND ar.`code` = 'observer'
  AND p.`code` NOT IN ('access', 'ticket.view.location');

-- E. Active grants, grouped by user.
SELECT
    u.`id` AS `user_id`,
    CONCAT_WS(' ', u.`first_name`, u.`last_name`) AS `user_name`,
    u.`email`,
    u.`status`,
    COUNT(*) AS `active_grants`,
    GROUP_CONCAT(
        CONCAT(ar.`code`, ' @ ', COALESCE(l.`name`, 'GLOBAL'))
        ORDER BY COALESCE(l.`sort_order`, 0), ar.`code`
        SEPARATOR ' | '
    ) AS `grant_summary`
FROM `u170017077_tools`.`maintenance_access_grants` mag
INNER JOIN `u170017077_tools`.`users` u
    ON u.`id` = mag.`user_id`
INNER JOIN `u170017077_tools`.`application_roles` ar
    ON ar.`id` = mag.`role_id`
   AND ar.`application_id` = mag.`application_id`
LEFT JOIN `u170017077_tools`.`locations` l
    ON l.`id` = mag.`location_id`
WHERE mag.`application_id` = @app_maintenance
  AND mag.`revoked_at` IS NULL
GROUP BY u.`id`, u.`first_name`, u.`last_name`, u.`email`, u.`status`
ORDER BY u.`id`;

-- F. Full active-grant detail.
SELECT
    mag.`id` AS `grant_id`,
    u.`email`,
    ar.`code` AS `role_code`,
    COALESCE(l.`code`, 'GLOBAL') AS `location_code`,
    COALESCE(l.`name`, 'GLOBAL') AS `location_name`,
    mag.`source_type`,
    mag.`source_id`,
    mag.`granted_at`,
    mag.`active_location_key`
FROM `u170017077_tools`.`maintenance_access_grants` mag
INNER JOIN `u170017077_tools`.`users` u
    ON u.`id` = mag.`user_id`
INNER JOIN `u170017077_tools`.`application_roles` ar
    ON ar.`id` = mag.`role_id`
   AND ar.`application_id` = mag.`application_id`
LEFT JOIN `u170017077_tools`.`locations` l
    ON l.`id` = mag.`location_id`
WHERE mag.`application_id` = @app_maintenance
  AND mag.`revoked_at` IS NULL
ORDER BY u.`id`, mag.`active_location_key`;

-- G. Patricio's intended grant set.
-- Expected exactly 2 rows: observer @ showroom_naos + showroom_the_wavve.
SELECT
    u.`email`,
    ar.`code` AS `role_code`,
    l.`code` AS `location_code`,
    l.`name` AS `location_name`
FROM `u170017077_tools`.`maintenance_access_grants` mag
INNER JOIN `u170017077_tools`.`users` u
    ON u.`id` = mag.`user_id`
INNER JOIN `u170017077_tools`.`application_roles` ar
    ON ar.`id` = mag.`role_id`
   AND ar.`application_id` = mag.`application_id`
INNER JOIN `u170017077_tools`.`locations` l
    ON l.`id` = mag.`location_id`
WHERE mag.`application_id` = @app_maintenance
  AND mag.`revoked_at` IS NULL
  AND u.`email` = 'prodriguez@estrategiaurbana.com.mx'
ORDER BY l.`sort_order`, l.`name`;

-- H. Invalid GLOBAL + territorial coexistence. Expected: zero rows.
SELECT
    u.`id` AS `user_id`,
    u.`email`,
    SUM(CASE WHEN mag.`location_id` IS NULL THEN 1 ELSE 0 END) AS `global_grants`,
    SUM(CASE WHEN mag.`location_id` IS NOT NULL THEN 1 ELSE 0 END) AS `territorial_grants`
FROM `u170017077_tools`.`maintenance_access_grants` mag
INNER JOIN `u170017077_tools`.`users` u
    ON u.`id` = mag.`user_id`
WHERE mag.`application_id` = @app_maintenance
  AND mag.`revoked_at` IS NULL
GROUP BY u.`id`, u.`email`
HAVING `global_grants` > 0
   AND `territorial_grants` > 0;

-- I. Duplicate active user/location grants. Expected: zero rows.
-- The unique trigger-maintained key should make this impossible.
SELECT
    mag.`user_id`,
    mag.`active_location_key`,
    COUNT(*) AS `duplicate_count`
FROM `u170017077_tools`.`maintenance_access_grants` mag
WHERE mag.`application_id` = @app_maintenance
  AND mag.`revoked_at` IS NULL
GROUP BY mag.`user_id`, mag.`active_location_key`
HAVING COUNT(*) > 1;

-- J. Grant-key triggers. Expected: exactly 2 rows.
SELECT
    `TRIGGER_NAME`,
    `EVENT_MANIPULATION`,
    `ACTION_TIMING`
FROM `information_schema`.`TRIGGERS`
WHERE `TRIGGER_SCHEMA` = 'u170017077_tools'
  AND `EVENT_OBJECT_TABLE` = 'maintenance_access_grants'
  AND `TRIGGER_NAME` IN (
      'trg_maintenance_grants_bi_active_key',
      'trg_maintenance_grants_bu_active_key'
  )
ORDER BY `TRIGGER_NAME`;

-- K. Invalid revocation chronology. Expected: zero rows.
SELECT
    `id` AS `grant_id`,
    `user_id`,
    `granted_at`,
    `revoked_at`
FROM `u170017077_tools`.`maintenance_access_grants`
WHERE `revoked_at` IS NOT NULL
  AND `revoked_at` < `granted_at`;

-- L. Active legacy Maintenance assignments. Expected: zero rows.
SELECT
    uar.`user_id`,
    u.`email`,
    ar.`code` AS `legacy_role_code`,
    uar.`assigned_at`
FROM `u170017077_tools`.`user_application_roles` uar
INNER JOIN `u170017077_tools`.`users` u
    ON u.`id` = uar.`user_id`
INNER JOIN `u170017077_tools`.`application_roles` ar
    ON ar.`id` = uar.`role_id`
   AND ar.`application_id` = uar.`application_id`
WHERE uar.`application_id` = @app_maintenance
  AND uar.`revoked_at` IS NULL;

-- M. Historical legacy assignments remain present for traceability.
SELECT
    uar.`user_id`,
    u.`email`,
    ar.`code` AS `legacy_role_code`,
    uar.`assigned_at`,
    uar.`revoked_at`,
    uar.`revoked_by_user_id`
FROM `u170017077_tools`.`user_application_roles` uar
INNER JOIN `u170017077_tools`.`users` u
    ON u.`id` = uar.`user_id`
INNER JOIN `u170017077_tools`.`application_roles` ar
    ON ar.`id` = uar.`role_id`
   AND ar.`application_id` = uar.`application_id`
WHERE uar.`application_id` = @app_maintenance
ORDER BY uar.`user_id`;
