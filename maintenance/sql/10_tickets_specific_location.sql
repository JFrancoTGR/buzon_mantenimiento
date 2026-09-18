-- Plataforma de Gestión de Mantenimiento
-- Migración 10: zona específica del desperfecto
-- Base de datos: u170017077_mantenimiento
-- Compatible con MariaDB / phpMyAdmin
--
-- Objetivos:
--   1. Agregar tickets.specific_location.
--   2. Mantener el dato directamente asociado al ticket.
--   3. Incluir la zona específica dentro de la búsqueda FULLTEXT.
--   4. Registrar la migración en schema_migrations.
--
-- Ejemplos de valor:
--   - Oficina de administración
--   - Baño de visitas, junto al lavabo
--   - Pasillo posterior del showroom
--
-- Consideraciones:
--   - No se utilizan restricciones CHECK.
--   - La aplicación deberá validar entre 3 y 255 caracteres.
--   - El campo se agrega primero como NULL para que la migración sea segura
--     incluso si existieran tickets previos.
--   - Los registros anteriores sin zona reciben "Sin especificar" antes de
--     convertir la columna a NOT NULL.

USE `u170017077_mantenimiento`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- =========================================================
-- 1. Agregar columna specific_location si todavía no existe
-- =========================================================

SET @column_exists := (
    SELECT COUNT(*)
    FROM `information_schema`.`columns`
    WHERE `table_schema` = DATABASE()
      AND `table_name` = 'tickets'
      AND `column_name` = 'specific_location'
);

SET @sql_add_column := IF(
    @column_exists = 0,
    'ALTER TABLE `tickets`
       ADD COLUMN `specific_location` VARCHAR(255) NULL
       AFTER `location_id`',
    'SELECT ''La columna specific_location ya existe'' AS message'
);

PREPARE stmt_add_column FROM @sql_add_column;
EXECUTE stmt_add_column;
DEALLOCATE PREPARE stmt_add_column;

-- =========================================================
-- 2. Proteger registros previos y convertir a NOT NULL
-- =========================================================

UPDATE `tickets`
SET `specific_location` = 'Sin especificar'
WHERE `specific_location` IS NULL
   OR TRIM(`specific_location`) = '';

SET @column_nullable := (
    SELECT `is_nullable`
    FROM `information_schema`.`columns`
    WHERE `table_schema` = DATABASE()
      AND `table_name` = 'tickets'
      AND `column_name` = 'specific_location'
    LIMIT 1
);

SET @sql_make_not_null := IF(
    @column_nullable = 'YES',
    'ALTER TABLE `tickets`
       MODIFY COLUMN `specific_location` VARCHAR(255) NOT NULL
       AFTER `location_id`',
    'SELECT ''La columna specific_location ya es NOT NULL'' AS message'
);

PREPARE stmt_make_not_null FROM @sql_make_not_null;
EXECUTE stmt_make_not_null;
DEALLOCATE PREPARE stmt_make_not_null;

-- =========================================================
-- 3. Crear índice FULLTEXT para la búsqueda operativa
-- =========================================================
-- Se crea un índice nuevo en lugar de depender del nombre de índices previos.
-- El backend podrá consultar:
-- MATCH(title, description, specific_location)
-- AGAINST (... IN BOOLEAN MODE)

SET @fulltext_index_exists := (
    SELECT COUNT(*)
    FROM `information_schema`.`statistics`
    WHERE `table_schema` = DATABASE()
      AND `table_name` = 'tickets'
      AND `index_name` = 'ft_tickets_search_v2'
);

SET @sql_add_fulltext := IF(
    @fulltext_index_exists = 0,
    'ALTER TABLE `tickets`
       ADD FULLTEXT INDEX `ft_tickets_search_v2`
       (`title`, `description`, `specific_location`)',
    'SELECT ''El índice ft_tickets_search_v2 ya existe'' AS message'
);

PREPARE stmt_add_fulltext FROM @sql_add_fulltext;
EXECUTE stmt_add_fulltext;
DEALLOCATE PREPARE stmt_add_fulltext;

-- =========================================================
-- 4. Registrar migración
-- =========================================================

INSERT INTO `schema_migrations` (`version`, `name`)
VALUES ('10', 'tickets_specific_location')
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);

-- =========================================================
-- 5. Validaciones
-- =========================================================

SELECT
    `column_name`,
    `column_type`,
    `is_nullable`,
    `character_maximum_length`
FROM `information_schema`.`columns`
WHERE `table_schema` = DATABASE()
  AND `table_name` = 'tickets'
  AND `column_name` = 'specific_location';

SELECT
    `index_name`,
    `index_type`,
    GROUP_CONCAT(`column_name` ORDER BY `seq_in_index`) AS `indexed_columns`
FROM `information_schema`.`statistics`
WHERE `table_schema` = DATABASE()
  AND `table_name` = 'tickets'
  AND `index_name` = 'ft_tickets_search_v2'
GROUP BY `index_name`, `index_type`;

SELECT
    COUNT(*) AS `tickets_without_specific_location`
FROM `tickets`
WHERE `specific_location` IS NULL
   OR TRIM(`specific_location`) = '';

SELECT
    `version`,
    `name`,
    `applied_at`
FROM `schema_migrations`
WHERE `version` = '10';

SHOW CREATE TABLE `tickets`;
