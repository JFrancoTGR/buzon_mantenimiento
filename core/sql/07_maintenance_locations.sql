-- EU Tools Core
-- Migration 07: Additional Maintenance locations
-- Database: u170017077_tools
--
-- Purpose:
--   Version the additional Maintenance locations already introduced in
--   production without modifying historical migration 03.
--
-- Notes:
--   default_supervisor_user_id remains environment/configuration data and is
--   intentionally not overwritten by this migration.

USE `u170017077_tools`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

INSERT INTO `locations`
    (`code`, `name`, `description`, `is_active`, `sort_order`, `default_supervisor_user_id`)
VALUES
    ('depa_muestra_naos', 'Depa Muestra NAOS', NULL, 1, 15, NULL),
    ('depa_muestra_the_wavve', 'Depa Muestra THE WAVVE', NULL, 1, 25, NULL),
    ('depa_muestra_juarez', 'Depa Muestra Juárez', NULL, 1, 35, NULL)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `is_active` = VALUES(`is_active`),
    `sort_order` = VALUES(`sort_order`);

SELECT
    `id`,
    `code`,
    `name`,
    `is_active`,
    `sort_order`,
    `default_supervisor_user_id`
FROM `locations`
WHERE `code` IN (
    'depa_muestra_naos',
    'depa_muestra_the_wavve',
    'depa_muestra_juarez'
)
ORDER BY `sort_order`, `id`;
