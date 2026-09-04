-- Plataforma de Gestión de Mantenimiento
-- Migración 12: vigencia de cotizaciones
-- Base de datos: u170017077_mantenimiento
-- Compatible con MariaDB / phpMyAdmin
--
-- Agrega una fecha de vigencia opcional a las cotizaciones y un índice
-- orientado a localizar rápidamente la cotización vigente de cada ticket.

USE `u170017077_mantenimiento`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- =========================================================
-- 1. Columna valid_until
-- =========================================================

SET @column_exists := (
    SELECT COUNT(*)
    FROM `information_schema`.`columns`
    WHERE `table_schema` = DATABASE()
      AND `table_name` = 'ticket_quotations'
      AND `column_name` = 'valid_until'
);

SET @sql_add_column := IF(
    @column_exists = 0,
    'ALTER TABLE `ticket_quotations`
       ADD COLUMN `valid_until` DATE NULL
       COMMENT ''Fecha de vigencia indicada por el proveedor''
       AFTER `currency`',
    'SELECT ''La columna valid_until ya existe'' AS message'
);

PREPARE stmt_add_column FROM @sql_add_column;
EXECUTE stmt_add_column;
DEALLOCATE PREPARE stmt_add_column;

ALTER TABLE `ticket_quotations`
    MODIFY COLUMN `valid_until` DATE NULL
    COMMENT 'Fecha de vigencia indicada por el proveedor'
    AFTER `currency`;

-- =========================================================
-- 2. Índice para cotizaciones vigentes
-- =========================================================

SET @index_exists := (
    SELECT COUNT(*)
    FROM `information_schema`.`statistics`
    WHERE `table_schema` = DATABASE()
      AND `table_name` = 'ticket_quotations'
      AND `index_name` = 'idx_ticket_quotations_validity'
);

SET @sql_add_index := IF(
    @index_exists = 0,
    'ALTER TABLE `ticket_quotations`
       ADD INDEX `idx_ticket_quotations_validity`
       (`ticket_id`, `is_current`, `valid_until`)',
    'SELECT ''El índice idx_ticket_quotations_validity ya existe'' AS message'
);

PREPARE stmt_add_index FROM @sql_add_index;
EXECUTE stmt_add_index;
DEALLOCATE PREPARE stmt_add_index;

-- =========================================================
-- 3. Registrar migración
-- =========================================================

INSERT INTO `schema_migrations` (`version`, `name`)
VALUES ('12', 'ticket_quotation_validity')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- =========================================================
-- 4. Validación
-- =========================================================

SELECT
    `column_name`,
    `column_type`,
    `is_nullable`,
    `column_default`,
    `column_comment`
FROM `information_schema`.`columns`
WHERE `table_schema` = DATABASE()
  AND `table_name` = 'ticket_quotations'
  AND `column_name` = 'valid_until';

SELECT
    `index_name`,
    GROUP_CONCAT(`column_name` ORDER BY `seq_in_index` SEPARATOR ', ') AS `indexed_columns`
FROM `information_schema`.`statistics`
WHERE `table_schema` = DATABASE()
  AND `table_name` = 'ticket_quotations'
  AND `index_name` = 'idx_ticket_quotations_validity'
GROUP BY `index_name`;

SELECT
    `version`,
    `name`,
    `applied_at`
FROM `schema_migrations`
WHERE `version` = '12';
