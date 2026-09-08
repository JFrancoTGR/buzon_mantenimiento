-- Plataforma de Gestión de Mantenimiento
-- Migración 15: administración de usuarios e invitaciones
-- Base de datos: u170017077_mantenimiento
-- Compatible con MariaDB / phpMyAdmin
--
-- Objetivos:
--   1. Separar el estado "invited" del estado "pending" usado por registro público.
--   2. Permitir que una identidad invitada exista sin contraseña hasta que su
--      propietario la establezca personalmente.
--   3. Crear tokens de invitación de un solo uso, revocables y con caducidad.
--
-- Reglas de dominio que se aplicarán desde PHP:
--   - status = 'invited' puede tener password_hash = NULL.
--   - status = 'active' debe tener password_hash válido.
--   - Los nuevos usuarios internos se crean con must_change_password = 0.
--   - El token original nunca se almacena; sólo se guarda SHA-256 en token_hash.
--   - El TTL (72 h por defecto) se calcula en backend y se materializa en expires_at.
--   - Al emitir una nueva invitación se revocan las anteriores no utilizadas.
--
-- IMPORTANTE:
--   - Esta migración NO modifica roles, permisos, user_roles ni usuarios existentes.
--   - No se agregan CHECK constraints para conservar compatibilidad con MariaDB.
--   - ALTER TABLE realiza commit implícito; por ello esta migración no pretende
--     envolver los cambios DDL en una única transacción.

USE `u170017077_mantenimiento`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- =========================================================
-- 1. users.status: agregar estado invited sin pisar
--    posibles ampliaciones futuras del ENUM
-- =========================================================

SET @users_status_has_invited := (
    SELECT COUNT(*)
    FROM `information_schema`.`columns`
    WHERE `table_schema` = DATABASE()
      AND `table_name` = 'users'
      AND `column_name` = 'status'
      AND LOCATE('''invited''', `column_type`) > 0
);

SET @sql_users_status := IF(
    @users_status_has_invited = 0,
    'ALTER TABLE `users`
       MODIFY COLUMN `status`
       ENUM(''active'',''inactive'',''blocked'',''pending'',''invited'')
       NOT NULL DEFAULT ''pending''',
    'SELECT ''users.status ya contiene invited'' AS message'
);

PREPARE stmt_users_status FROM @sql_users_status;
EXECUTE stmt_users_status;
DEALLOCATE PREPARE stmt_users_status;

-- =========================================================
-- 2. users.password_hash: admitir NULL únicamente como
--    soporte estructural para identidades invitadas
-- =========================================================

SET @password_hash_is_not_nullable := (
    SELECT COUNT(*)
    FROM `information_schema`.`columns`
    WHERE `table_schema` = DATABASE()
      AND `table_name` = 'users'
      AND `column_name` = 'password_hash'
      AND `is_nullable` = 'NO'
);

SET @sql_password_hash_nullable := IF(
    @password_hash_is_not_nullable > 0,
    'ALTER TABLE `users`
       MODIFY COLUMN `password_hash` VARCHAR(255) NULL',
    'SELECT ''users.password_hash ya admite NULL'' AS message'
);

PREPARE stmt_password_hash_nullable FROM @sql_password_hash_nullable;
EXECUTE stmt_password_hash_nullable;
DEALLOCATE PREPARE stmt_password_hash_nullable;

-- =========================================================
-- 3. Tokens de invitación administrativa
-- =========================================================

CREATE TABLE IF NOT EXISTS `user_invitation_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `revoked_at` DATETIME NULL,
    `created_by_user_id` BIGINT UNSIGNED NOT NULL,
    `requested_ip` VARCHAR(45) NULL,
    `user_agent` VARCHAR(500) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    UNIQUE KEY `uq_user_invitation_token_hash`
        (`token_hash`),

    KEY `idx_user_invitation_user_created`
        (`user_id`, `created_at`),

    KEY `idx_user_invitation_user_expiry`
        (`user_id`, `expires_at`),

    KEY `idx_user_invitation_creator_created`
        (`created_by_user_id`, `created_at`),

    KEY `idx_user_invitation_cleanup`
        (`expires_at`, `used_at`, `revoked_at`),

    CONSTRAINT `fk_user_invitation_user`
        FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT `fk_user_invitation_creator`
        FOREIGN KEY (`created_by_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 4. Registrar migración
-- =========================================================

INSERT INTO `schema_migrations` (`version`, `name`)
VALUES ('15', 'user_administration_invitations')
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);

-- =========================================================
-- VALIDACIONES
-- =========================================================

-- 1) users.status debe contener invited.
SELECT
    CASE
        WHEN LOCATE('''invited''', `column_type`) > 0 THEN 'OK'
        ELSE 'ERROR'
    END AS `invited_status_validation`,
    `column_type` AS `users_status_definition`,
    `column_default` AS `users_status_default`
FROM `information_schema`.`columns`
WHERE `table_schema` = DATABASE()
  AND `table_name` = 'users'
  AND `column_name` = 'status';

-- 2) password_hash debe admitir NULL.
SELECT
    CASE
        WHEN `is_nullable` = 'YES' THEN 'OK'
        ELSE 'ERROR'
    END AS `password_hash_nullable_validation`,
    `column_type`,
    `is_nullable`
FROM `information_schema`.`columns`
WHERE `table_schema` = DATABASE()
  AND `table_name` = 'users'
  AND `column_name` = 'password_hash';

-- 3) La tabla de invitaciones debe existir.
SELECT
    CASE
        WHEN COUNT(*) = 1 THEN 'OK'
        ELSE 'ERROR'
    END AS `invitation_table_validation`,
    COUNT(*) AS `tables_found`
FROM `information_schema`.`tables`
WHERE `table_schema` = DATABASE()
  AND `table_name` = 'user_invitation_tokens';

-- 4) Debe existir el índice UNIQUE de token_hash.
SELECT
    CASE
        WHEN COUNT(*) = 1 THEN 'OK'
        ELSE 'ERROR'
    END AS `token_hash_unique_validation`,
    COUNT(*) AS `indexes_found`
FROM (
    SELECT `index_name`
    FROM `information_schema`.`statistics`
    WHERE `table_schema` = DATABASE()
      AND `table_name` = 'user_invitation_tokens'
      AND `index_name` = 'uq_user_invitation_token_hash'
      AND `non_unique` = 0
    GROUP BY `index_name`
) invitation_unique_index;

-- 5) Deben existir ambas foreign keys.
SELECT
    CASE
        WHEN COUNT(*) = 2 THEN 'OK'
        ELSE 'ERROR'
    END AS `foreign_keys_validation`,
    COUNT(*) AS `foreign_keys_found`
FROM `information_schema`.`table_constraints`
WHERE `constraint_schema` = DATABASE()
  AND `table_name` = 'user_invitation_tokens'
  AND `constraint_type` = 'FOREIGN KEY'
  AND `constraint_name` IN (
      'fk_user_invitation_user',
      'fk_user_invitation_creator'
  );

-- 6) La regla contractual de un solo rol por cuenta debe seguir presente.
SELECT
    CASE
        WHEN COUNT(*) = 1 THEN 'OK'
        ELSE 'ERROR'
    END AS `single_role_index_validation`,
    COUNT(*) AS `indexes_found`
FROM (
    SELECT `index_name`
    FROM `information_schema`.`statistics`
    WHERE `table_schema` = DATABASE()
      AND `table_name` = 'user_roles'
      AND `index_name` = 'uq_user_roles_single_role'
      AND `non_unique` = 0
    GROUP BY `index_name`
) single_role_unique_index;

-- 7) Invariante crítica: ninguna cuenta activa puede carecer de contraseña.
-- Debe ser 0.
SELECT COUNT(*) AS `active_users_without_password`
FROM `users`
WHERE `status` = 'active'
  AND `password_hash` IS NULL;

-- 8) Fuera del estado invited tampoco debe haber cuentas sin contraseña.
-- Debe ser 0. Esta consulta seguirá siendo válida cuando ya existan invitaciones.
SELECT COUNT(*) AS `non_invited_users_without_password`
FROM `users`
WHERE `status` <> 'invited'
  AND `password_hash` IS NULL;

-- 9) Verificación de registro de migración.
SELECT
    `version`,
    `name`,
    `applied_at`
FROM `schema_migrations`
WHERE `version` = '15';

-- 10) Inspección final de la tabla nueva.
SHOW CREATE TABLE `user_invitation_tokens`;
