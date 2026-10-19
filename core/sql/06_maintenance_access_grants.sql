-- EU Tools Core / Maintenance
-- Migration 06: Maintenance access grants cutover
-- Database: u170017077_tools
-- Target DB: MariaDB 11.8.x
--
-- Purpose:
--   Replace Maintenance's legacy one-role-per-application assignment with
--   location-aware access grants.
--
-- Authorization model after cutover:
--   Grant  = user + Maintenance role/level + location
--   Scopes = inherited from role_permissions (not copied into the grant)
--
-- Important deployment note:
--   This is a CUTOVER migration. After it completes, active Maintenance rows
--   in user_application_roles are revoked. Deploy the grant-aware Maintenance
--   authorization resolver and Core Users UI as part of the same release window.
--
-- Backfill policy for the current pre-launch database:
--   * administrator -> one GLOBAL grant (location_id = NULL)
--   * reporter/supervisor/director -> one grant for every active location,
--     preserving the old unrestricted-location behavior
--   * observer -> same preservation rule EXCEPT Patricio Rodriguez, whose
--     intended scope is explicitly limited to:
--       - showroom_naos
--       - showroom_the_wavve
--
-- The script is intentionally rerunnable before version 06 is registered.

USE `u170017077_tools`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @cutover_at := UTC_TIMESTAMP();

SET @app_maintenance := (
    SELECT `id`
    FROM `applications`
    WHERE `code` = 'maintenance'
    LIMIT 1
);

SET @migration_actor_user_id := (
    SELECT `id`
    FROM `users`
    WHERE `email` = 'jfranco@estrategiaurbana.com.mx'
    LIMIT 1
);

SET @role_observer := (
    SELECT `id`
    FROM `application_roles`
    WHERE `application_id` = @app_maintenance
      AND `code` = 'observer'
    LIMIT 1
);

SET @patricio_user_id := (
    SELECT `id`
    FROM `users`
    WHERE `email` = 'prodriguez@estrategiaurbana.com.mx'
    LIMIT 1
);

-- =========================================================
-- A. Grant infrastructure
-- =========================================================
--
-- location_id NULL means GLOBAL scope.
-- active_location_key is maintained by database triggers:
--   0             -> GLOBAL
--   location_id   -> territorial grant
--   NULL          -> historical/revoked grant
--
-- The unique key guarantees at most one active grant per user + location.
-- Global-vs-territorial coexistence is a domain rule and is validated below;
-- it will also be enforced transactionally by the application service.

CREATE TABLE IF NOT EXISTS `maintenance_access_grants` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `application_id` SMALLINT UNSIGNED NOT NULL,
    `role_id` SMALLINT UNSIGNED NOT NULL,
    `location_id` SMALLINT UNSIGNED NULL,
    `source_type` VARCHAR(30) NOT NULL,
    `source_id` BIGINT UNSIGNED NULL,
    `granted_by_user_id` BIGINT UNSIGNED NULL,
    `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `revoked_by_user_id` BIGINT UNSIGNED NULL,
    `revoked_at` DATETIME NULL,
    `revocation_reason` VARCHAR(120) NULL,
    `active_location_key` SMALLINT UNSIGNED NULL
        COMMENT 'Integrity slot: 0=GLOBAL, location_id=territorial, NULL=revoked',

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_maintenance_grants_active_location`
        (`user_id`, `application_id`, `active_location_key`),
    KEY `idx_maintenance_grants_user_active`
        (`user_id`, `application_id`, `revoked_at`, `location_id`, `role_id`),
    KEY `idx_maintenance_grants_location_active`
        (`location_id`, `application_id`, `revoked_at`, `role_id`, `user_id`),
    KEY `idx_maintenance_grants_role_active`
        (`role_id`, `application_id`, `revoked_at`, `location_id`),
    KEY `idx_maintenance_grants_source`
        (`source_type`, `source_id`),
    KEY `idx_maintenance_grants_granted_by`
        (`granted_by_user_id`),
    KEY `idx_maintenance_grants_revoked_by`
        (`revoked_by_user_id`),

    CONSTRAINT `fk_maintenance_grants_user`
        FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT `fk_maintenance_grants_application`
        FOREIGN KEY (`application_id`)
        REFERENCES `applications` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT `fk_maintenance_grants_role_app`
        FOREIGN KEY (`role_id`, `application_id`)
        REFERENCES `application_roles` (`id`, `application_id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT `fk_maintenance_grants_location`
        FOREIGN KEY (`location_id`)
        REFERENCES `locations` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT `fk_maintenance_grants_granted_by`
        FOREIGN KEY (`granted_by_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT `fk_maintenance_grants_revoked_by`
        FOREIGN KEY (`revoked_by_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- The production MariaDB host rejects the original generated-column
-- expression for active_location_key. Keep it as a normal integrity column
-- and maintain it centrally with triggers:
--   active GLOBAL grant       -> 0
--   active territorial grant  -> location_id
--   revoked grant             -> NULL
--
-- The UNIQUE key therefore still enforces at most one active grant per
-- user + application + location without relying on generated columns.

DROP TRIGGER IF EXISTS `trg_maintenance_grants_bi_active_key`;

CREATE TRIGGER `trg_maintenance_grants_bi_active_key`
BEFORE INSERT ON `maintenance_access_grants`
FOR EACH ROW
SET NEW.`active_location_key` =
    CASE
        WHEN NEW.`revoked_at` IS NULL THEN IFNULL(NEW.`location_id`, 0)
        ELSE NULL
    END;

DROP TRIGGER IF EXISTS `trg_maintenance_grants_bu_active_key`;

CREATE TRIGGER `trg_maintenance_grants_bu_active_key`
BEFORE UPDATE ON `maintenance_access_grants`
FOR EACH ROW
SET NEW.`active_location_key` =
    CASE
        WHEN NEW.`revoked_at` IS NULL THEN IFNULL(NEW.`location_id`, 0)
        ELSE NULL
    END;

-- DDL can implicitly commit in MariaDB. All following cutover DML is grouped
-- into one transaction.
START TRANSACTION;

-- =========================================================
-- B. Observer matrix: global -> location-aware
-- =========================================================

INSERT INTO `permissions` (
    `application_id`,
    `code`,
    `module`,
    `name`,
    `description`
)
SELECT
    @app_maintenance,
    'ticket.view.location',
    'tickets',
    'Ver tickets de ubicación',
    'Permite consultar todos los tickets dentro de las ubicaciones autorizadas por grants.'
WHERE @app_maintenance IS NOT NULL
ON DUPLICATE KEY UPDATE
    `module` = VALUES(`module`),
    `name` = VALUES(`name`),
    `description` = VALUES(`description`);

SET @permission_view_location := (
    SELECT `id`
    FROM `permissions`
    WHERE `application_id` = @app_maintenance
      AND `code` = 'ticket.view.location'
    LIMIT 1
);

DELETE rp
FROM `role_permissions` rp
INNER JOIN `permissions` p
    ON p.`id` = rp.`permission_id`
   AND p.`application_id` = rp.`application_id`
WHERE rp.`application_id` = @app_maintenance
  AND rp.`role_id` = @role_observer
  AND p.`code` = 'ticket.view.all';

INSERT INTO `role_permissions` (
    `application_id`,
    `role_id`,
    `permission_id`
)
SELECT
    @app_maintenance,
    @role_observer,
    @permission_view_location
WHERE @app_maintenance IS NOT NULL
  AND @role_observer IS NOT NULL
  AND @permission_view_location IS NOT NULL
ON DUPLICATE KEY UPDATE
    `assigned_at` = `assigned_at`;

UPDATE `application_roles`
SET `description` = 'Consulta tickets y su trazabilidad en las ubicaciones autorizadas sin intervenir en el flujo operativo.'
WHERE `id` = @role_observer
  AND `application_id` = @app_maintenance;

-- =========================================================
-- C. Backfill grants from current legacy assignments
-- =========================================================

-- C1. Administrators become GLOBAL.
INSERT INTO `maintenance_access_grants` (
    `user_id`,
    `application_id`,
    `role_id`,
    `location_id`,
    `source_type`,
    `source_id`,
    `granted_by_user_id`,
    `granted_at`
)
SELECT
    uar.`user_id`,
    uar.`application_id`,
    uar.`role_id`,
    NULL,
    'migration',
    NULL,
    uar.`assigned_by_user_id`,
    uar.`assigned_at`
FROM `user_application_roles` uar
INNER JOIN `application_roles` ar
    ON ar.`id` = uar.`role_id`
   AND ar.`application_id` = uar.`application_id`
WHERE uar.`application_id` = @app_maintenance
  AND uar.`revoked_at` IS NULL
  AND ar.`code` = 'administrator'
  AND NOT EXISTS (
      SELECT 1
      FROM `maintenance_access_grants` mag
      WHERE mag.`user_id` = uar.`user_id`
        AND mag.`application_id` = uar.`application_id`
        AND mag.`location_id` IS NULL
        AND mag.`revoked_at` IS NULL
  );

-- C2. Legacy Reporter/Supervisor/Director had no location restriction.
-- Preserve that behavior by granting every currently active location.
INSERT INTO `maintenance_access_grants` (
    `user_id`,
    `application_id`,
    `role_id`,
    `location_id`,
    `source_type`,
    `source_id`,
    `granted_by_user_id`,
    `granted_at`
)
SELECT
    uar.`user_id`,
    uar.`application_id`,
    uar.`role_id`,
    l.`id`,
    'migration',
    NULL,
    uar.`assigned_by_user_id`,
    uar.`assigned_at`
FROM `user_application_roles` uar
INNER JOIN `application_roles` ar
    ON ar.`id` = uar.`role_id`
   AND ar.`application_id` = uar.`application_id`
INNER JOIN `locations` l
    ON l.`is_active` = 1
WHERE uar.`application_id` = @app_maintenance
  AND uar.`revoked_at` IS NULL
  AND ar.`code` IN ('reporter', 'supervisor', 'director')
  AND NOT EXISTS (
      SELECT 1
      FROM `maintenance_access_grants` mag
      WHERE mag.`user_id` = uar.`user_id`
        AND mag.`application_id` = uar.`application_id`
        AND mag.`location_id` = l.`id`
        AND mag.`revoked_at` IS NULL
  );

-- C3. Preserve old unrestricted behavior for any legacy Observer other than
-- Patricio Rodriguez. This makes the migration robust if another Observer was
-- created after the exported snapshot but before cutover.
INSERT INTO `maintenance_access_grants` (
    `user_id`,
    `application_id`,
    `role_id`,
    `location_id`,
    `source_type`,
    `source_id`,
    `granted_by_user_id`,
    `granted_at`
)
SELECT
    uar.`user_id`,
    uar.`application_id`,
    uar.`role_id`,
    l.`id`,
    'migration',
    NULL,
    uar.`assigned_by_user_id`,
    uar.`assigned_at`
FROM `user_application_roles` uar
INNER JOIN `application_roles` ar
    ON ar.`id` = uar.`role_id`
   AND ar.`application_id` = uar.`application_id`
INNER JOIN `locations` l
    ON l.`is_active` = 1
WHERE uar.`application_id` = @app_maintenance
  AND uar.`revoked_at` IS NULL
  AND ar.`code` = 'observer'
  AND (@patricio_user_id IS NULL OR uar.`user_id` <> @patricio_user_id)
  AND NOT EXISTS (
      SELECT 1
      FROM `maintenance_access_grants` mag
      WHERE mag.`user_id` = uar.`user_id`
        AND mag.`application_id` = uar.`application_id`
        AND mag.`location_id` = l.`id`
        AND mag.`revoked_at` IS NULL
  );

-- C4. Patricio Rodriguez: intended Observer scope = NAOS + THE WAVVE only.
INSERT INTO `maintenance_access_grants` (
    `user_id`,
    `application_id`,
    `role_id`,
    `location_id`,
    `source_type`,
    `source_id`,
    `granted_by_user_id`,
    `granted_at`
)
SELECT
    uar.`user_id`,
    uar.`application_id`,
    uar.`role_id`,
    l.`id`,
    'migration',
    NULL,
    uar.`assigned_by_user_id`,
    uar.`assigned_at`
FROM `user_application_roles` uar
INNER JOIN `application_roles` ar
    ON ar.`id` = uar.`role_id`
   AND ar.`application_id` = uar.`application_id`
INNER JOIN `locations` l
    ON l.`code` IN ('showroom_naos', 'showroom_the_wavve')
   AND l.`is_active` = 1
WHERE uar.`application_id` = @app_maintenance
  AND uar.`revoked_at` IS NULL
  AND ar.`code` = 'observer'
  AND uar.`user_id` = @patricio_user_id
  AND NOT EXISTS (
      SELECT 1
      FROM `maintenance_access_grants` mag
      WHERE mag.`user_id` = uar.`user_id`
        AND mag.`application_id` = uar.`application_id`
        AND mag.`location_id` = l.`id`
        AND mag.`revoked_at` IS NULL
  );

-- =========================================================
-- D. Retire legacy Maintenance assignments
-- =========================================================
-- Only revoke a legacy assignment after at least one active grant exists for
-- that user. This prevents destructive cutover if an unexpected role/user was
-- introduced between snapshot and deployment.

UPDATE `user_application_roles` uar
SET
    uar.`revoked_by_user_id` = @migration_actor_user_id,
    uar.`revoked_at` = @cutover_at
WHERE uar.`application_id` = @app_maintenance
  AND uar.`revoked_at` IS NULL
  AND EXISTS (
      SELECT 1
      FROM `maintenance_access_grants` mag
      WHERE mag.`user_id` = uar.`user_id`
        AND mag.`application_id` = uar.`application_id`
        AND mag.`revoked_at` IS NULL
  );

-- =========================================================
-- E. Register migration only after a complete Maintenance cutover
-- =========================================================

INSERT INTO `schema_migrations` (`version`, `name`)
SELECT '06', 'maintenance_access_grants'
WHERE @app_maintenance IS NOT NULL
  AND @permission_view_location IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM `user_application_roles`
      WHERE `application_id` = @app_maintenance
        AND `revoked_at` IS NULL
  )
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);

COMMIT;

-- =========================================================
-- Immediate validation output
-- =========================================================

-- 1. Migration registration. Expected: one row, version 06.
SELECT
    `version`,
    `name`,
    `applied_at`
FROM `schema_migrations`
WHERE `version` = '06';

-- 2. Observer permissions. Expected exactly:
--      access
--      ticket.view.location
SELECT
    ar.`code` AS `role_code`,
    p.`code` AS `permission_code`,
    p.`name` AS `permission_name`
FROM `role_permissions` rp
INNER JOIN `application_roles` ar
    ON ar.`id` = rp.`role_id`
   AND ar.`application_id` = rp.`application_id`
INNER JOIN `permissions` p
    ON p.`id` = rp.`permission_id`
   AND p.`application_id` = rp.`application_id`
WHERE rp.`application_id` = @app_maintenance
  AND ar.`code` = 'observer'
ORDER BY p.`code`;

-- 3. Active grants after cutover.
SELECT
    mag.`id` AS `grant_id`,
    u.`id` AS `user_id`,
    CONCAT_WS(' ', u.`first_name`, u.`last_name`) AS `user_name`,
    u.`email`,
    u.`status`,
    ar.`code` AS `role_code`,
    COALESCE(l.`code`, 'GLOBAL') AS `location_code`,
    COALESCE(l.`name`, 'GLOBAL') AS `location_name`,
    mag.`source_type`,
    mag.`granted_at`
FROM `maintenance_access_grants` mag
INNER JOIN `users` u
    ON u.`id` = mag.`user_id`
INNER JOIN `application_roles` ar
    ON ar.`id` = mag.`role_id`
   AND ar.`application_id` = mag.`application_id`
LEFT JOIN `locations` l
    ON l.`id` = mag.`location_id`
WHERE mag.`application_id` = @app_maintenance
  AND mag.`revoked_at` IS NULL
ORDER BY u.`id`, mag.`active_location_key`;

-- 4. Legacy active Maintenance assignments. Expected: zero rows.
SELECT
    uar.`user_id`,
    u.`email`,
    ar.`code` AS `legacy_role_code`
FROM `user_application_roles` uar
INNER JOIN `users` u
    ON u.`id` = uar.`user_id`
INNER JOIN `application_roles` ar
    ON ar.`id` = uar.`role_id`
   AND ar.`application_id` = uar.`application_id`
WHERE uar.`application_id` = @app_maintenance
  AND uar.`revoked_at` IS NULL;

-- 5. Invalid GLOBAL + territorial coexistence. Expected: zero rows.
SELECT
    u.`id` AS `user_id`,
    u.`email`,
    SUM(CASE WHEN mag.`location_id` IS NULL THEN 1 ELSE 0 END) AS `global_grants`,
    SUM(CASE WHEN mag.`location_id` IS NOT NULL THEN 1 ELSE 0 END) AS `territorial_grants`
FROM `maintenance_access_grants` mag
INNER JOIN `users` u
    ON u.`id` = mag.`user_id`
WHERE mag.`application_id` = @app_maintenance
  AND mag.`revoked_at` IS NULL
GROUP BY u.`id`, u.`email`
HAVING `global_grants` > 0
   AND `territorial_grants` > 0;
