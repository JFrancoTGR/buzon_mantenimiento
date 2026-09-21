-- EU Tools Core
-- Migration 04: Email log entity context
-- Database: u170017077_tools
--
-- Purpose:
--   Allow the global email delivery log to associate any message
--   with the domain entity that originated it.
--
-- Examples:
--   core        + user   + 25
--   maintenance + ticket + 104
--   events      + event  + 8
--
-- entity_id intentionally has no FK because the referenced table
-- depends on entity_type and application_id.

USE `u170017077_tools`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE `email_log`
    ADD COLUMN IF NOT EXISTS `entity_type`
        VARCHAR(80) NULL AFTER `event_code`,
    ADD COLUMN IF NOT EXISTS `entity_id`
        BIGINT UNSIGNED NULL AFTER `entity_type`,
    ADD INDEX IF NOT EXISTS `idx_email_log_entity`
        (`application_id`, `entity_type`, `entity_id`, `created_at`);

INSERT INTO `schema_migrations` (`version`, `name`)
VALUES ('04', 'email_log_entity_context')
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);

-- =========================================================
-- Validation
-- =========================================================

SELECT
    `COLUMN_NAME`,
    `COLUMN_TYPE`,
    `IS_NULLABLE`
FROM `information_schema`.`COLUMNS`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` = 'email_log'
  AND `COLUMN_NAME` IN ('entity_type', 'entity_id')
ORDER BY `ORDINAL_POSITION`;

SELECT
    `INDEX_NAME`,
    GROUP_CONCAT(`COLUMN_NAME` ORDER BY `SEQ_IN_INDEX`) AS `columns`
FROM `information_schema`.`STATISTICS`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` = 'email_log'
  AND `INDEX_NAME` = 'idx_email_log_entity'
GROUP BY `INDEX_NAME`;

SELECT
    `version`,
    `name`,
    `applied_at`
FROM `schema_migrations`
WHERE `version` = '04';