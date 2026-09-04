-- Plataforma de Gestión de Mantenimiento
-- Migración 09: supervisor predeterminado por ubicación
-- Base de datos: u170017077_mantenimiento
-- Compatible con MariaDB / phpMyAdmin
--
-- Objetivos:
--   1. Agregar locations.default_supervisor_user_id.
--   2. Crear índice y llave foránea hacia users.id.
--   3. Asignar temporalmente las cuatro ubicaciones al administrador de QA.
--   4. Registrar la migración en schema_migrations.
--
-- Consideraciones:
--   - La columna admite NULL para no bloquear la creación de ubicaciones.
--   - ON DELETE SET NULL evita referencias inválidas si una cuenta fuera eliminada.
--   - Los tickets ya creados no se modifican.
--   - La asignación se realiza por correo, no por un ID hardcodeado.
--   - No se utilizan restricciones CHECK.

USE `u170017077_mantenimiento`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- =========================================================
-- 1. Agregar columna de supervisor predeterminado
-- =========================================================

SET @column_exists := (
    SELECT COUNT(*)
    FROM `information_schema`.`columns`
    WHERE `table_schema` = DATABASE()
      AND `table_name` = 'locations'
      AND `column_name` = 'default_supervisor_user_id'
);

SET @sql_add_column := IF(
    @column_exists = 0,
    'ALTER TABLE `locations`
       ADD COLUMN `default_supervisor_user_id` BIGINT UNSIGNED NULL
       AFTER `sort_order`',
    'SELECT ''La columna default_supervisor_user_id ya existe'' AS message'
);

PREPARE stmt_add_column FROM @sql_add_column;
EXECUTE stmt_add_column;
DEALLOCATE PREPARE stmt_add_column;

-- =========================================================
-- 2. Crear índice para consultas y asignación automática
-- =========================================================

SET @index_exists := (
    SELECT COUNT(*)
    FROM `information_schema`.`statistics`
    WHERE `table_schema` = DATABASE()
      AND `table_name` = 'locations'
      AND `index_name` = 'idx_locations_default_supervisor'
);

SET @sql_add_index := IF(
    @index_exists = 0,
    'ALTER TABLE `locations`
       ADD INDEX `idx_locations_default_supervisor`
       (`default_supervisor_user_id`)',
    'SELECT ''El índice idx_locations_default_supervisor ya existe'' AS message'
);

PREPARE stmt_add_index FROM @sql_add_index;
EXECUTE stmt_add_index;
DEALLOCATE PREPARE stmt_add_index;

-- =========================================================
-- 3. Crear llave foránea hacia users.id
-- =========================================================

SET @fk_exists := (
    SELECT COUNT(*)
    FROM `information_schema`.`table_constraints`
    WHERE `constraint_schema` = DATABASE()
      AND `table_name` = 'locations'
      AND `constraint_name` = 'fk_locations_default_supervisor_user'
      AND `constraint_type` = 'FOREIGN KEY'
);

SET @sql_add_fk := IF(
    @fk_exists = 0,
    'ALTER TABLE `locations`
       ADD CONSTRAINT `fk_locations_default_supervisor_user`
       FOREIGN KEY (`default_supervisor_user_id`)
       REFERENCES `users` (`id`)
       ON UPDATE CASCADE
       ON DELETE SET NULL',
    'SELECT ''La llave foránea fk_locations_default_supervisor_user ya existe'' AS message'
);

PREPARE stmt_add_fk FROM @sql_add_fk;
EXECUTE stmt_add_fk;
DEALLOCATE PREPARE stmt_add_fk;

-- =========================================================
-- 4. Resolver al supervisor temporal de QA por correo
-- =========================================================

SET @qa_supervisor_email := 'jfranco@estrategiaurbana.com.mx';

SET @qa_supervisor_id := (
    SELECT `id`
    FROM `users`
    WHERE LOWER(`email`) = LOWER(@qa_supervisor_email)
      AND `status` = 'active'
    ORDER BY `id`
    LIMIT 1
);

-- Asignar únicamente las cuatro ubicaciones actuales.
-- Si el usuario no existe o no está activo, no se modifica ninguna fila.
UPDATE `locations`
SET
    `default_supervisor_user_id` = @qa_supervisor_id,
    `updated_at` = CURRENT_TIMESTAMP
WHERE @qa_supervisor_id IS NOT NULL
  AND `code` IN (
      'showroom_naos',
      'showroom_the_wavve',
      'showroom_estrategia_urbana',
      'corporativo_estrategia_urbana'
  );

-- =========================================================
-- 5. Registrar migración
-- =========================================================

INSERT INTO `schema_migrations` (`version`, `name`)
VALUES ('09', 'locations_default_supervisor')
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);

-- =========================================================
-- 6. Validaciones
-- =========================================================

SELECT
    CASE
        WHEN @qa_supervisor_id IS NOT NULL THEN 'OK'
        ELSE 'ERROR: no se encontró un usuario activo con el correo configurado'
    END AS `qa_supervisor_validation`,
    @qa_supervisor_id AS `qa_supervisor_user_id`,
    @qa_supervisor_email AS `qa_supervisor_email`;

SELECT
    l.`id`,
    l.`code`,
    l.`name` AS `location_name`,
    l.`default_supervisor_user_id`,
    CONCAT(u.`first_name`, ' ', u.`last_name`) AS `supervisor_name`,
    u.`email` AS `supervisor_email`,
    u.`status` AS `supervisor_status`
FROM `locations` l
LEFT JOIN `users` u
    ON u.`id` = l.`default_supervisor_user_id`
WHERE l.`code` IN (
    'showroom_naos',
    'showroom_the_wavve',
    'showroom_estrategia_urbana',
    'corporativo_estrategia_urbana'
)
ORDER BY l.`sort_order`, l.`id`;

SELECT
    COUNT(*) AS `locations_assigned_to_qa_supervisor`
FROM `locations`
WHERE `default_supervisor_user_id` = @qa_supervisor_id
  AND `code` IN (
      'showroom_naos',
      'showroom_the_wavve',
      'showroom_estrategia_urbana',
      'corporativo_estrategia_urbana'
  );

SELECT
    `version`,
    `name`,
    `applied_at`
FROM `schema_migrations`
WHERE `version` = '09';

SHOW CREATE TABLE `locations`;
