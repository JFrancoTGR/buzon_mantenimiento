-- Plataforma de Gestión de Mantenimiento
-- Migración 16: hardening de tokens de recuperación de contraseña
-- Base de datos: u170017077_mantenimiento
-- Compatible con MariaDB / phpMyAdmin
--
-- Objetivos:
--   1. Homologar password_reset_tokens con el patrón de seguridad de user_invitation_tokens.
--   2. Diferenciar tokens utilizados de tokens revocados.
--   3. Conservar trazabilidad mínima de la solicitud (IP y User-Agent).
--   4. Preparar índices para cooldown/rate limiting y limpieza de tokens.
--
-- IMPORTANTE:
--   - No se recrea password_reset_tokens.
--   - No se eliminan columnas, índices ni datos existentes.
--   - El token original nunca deberá almacenarse; sólo SHA-256 en token_hash.
--   - ALTER TABLE realiza commit implícito en MariaDB.

USE `u170017077_mantenimiento`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @has_revoked_at := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'u170017077_mantenimiento'
      AND table_name = 'password_reset_tokens'
      AND column_name = 'revoked_at'
);
SET @sql_revoked_at := IF(
    @has_revoked_at = 0,
    'ALTER TABLE `u170017077_mantenimiento`.`password_reset_tokens` ADD COLUMN `revoked_at` DATETIME NULL AFTER `used_at`',
    'SELECT ''password_reset_tokens.revoked_at ya existe'' AS message'
);
PREPARE stmt_revoked_at FROM @sql_revoked_at;
EXECUTE stmt_revoked_at;
DEALLOCATE PREPARE stmt_revoked_at;

SET @has_requested_ip := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'u170017077_mantenimiento'
      AND table_name = 'password_reset_tokens'
      AND column_name = 'requested_ip'
);
SET @sql_requested_ip := IF(
    @has_requested_ip = 0,
    'ALTER TABLE `u170017077_mantenimiento`.`password_reset_tokens` ADD COLUMN `requested_ip` VARCHAR(45) NULL AFTER `revoked_at`',
    'SELECT ''password_reset_tokens.requested_ip ya existe'' AS message'
);
PREPARE stmt_requested_ip FROM @sql_requested_ip;
EXECUTE stmt_requested_ip;
DEALLOCATE PREPARE stmt_requested_ip;

SET @has_user_agent := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'u170017077_mantenimiento'
      AND table_name = 'password_reset_tokens'
      AND column_name = 'user_agent'
);
SET @sql_user_agent := IF(
    @has_user_agent = 0,
    'ALTER TABLE `u170017077_mantenimiento`.`password_reset_tokens` ADD COLUMN `user_agent` VARCHAR(500) NULL AFTER `requested_ip`',
    'SELECT ''password_reset_tokens.user_agent ya existe'' AS message'
);
PREPARE stmt_user_agent FROM @sql_user_agent;
EXECUTE stmt_user_agent;
DEALLOCATE PREPARE stmt_user_agent;

SET @has_user_created_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = 'u170017077_mantenimiento'
      AND table_name = 'password_reset_tokens'
      AND index_name = 'idx_password_reset_user_created'
);
SET @sql_user_created_idx := IF(
    @has_user_created_idx = 0,
    'ALTER TABLE `u170017077_mantenimiento`.`password_reset_tokens` ADD KEY `idx_password_reset_user_created` (`user_id`, `created_at`)',
    'SELECT ''idx_password_reset_user_created ya existe'' AS message'
);
PREPARE stmt_user_created_idx FROM @sql_user_created_idx;
EXECUTE stmt_user_created_idx;
DEALLOCATE PREPARE stmt_user_created_idx;

SET @has_ip_created_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = 'u170017077_mantenimiento'
      AND table_name = 'password_reset_tokens'
      AND index_name = 'idx_password_reset_ip_created'
);
SET @sql_ip_created_idx := IF(
    @has_ip_created_idx = 0,
    'ALTER TABLE `u170017077_mantenimiento`.`password_reset_tokens` ADD KEY `idx_password_reset_ip_created` (`requested_ip`, `created_at`)',
    'SELECT ''idx_password_reset_ip_created ya existe'' AS message'
);
PREPARE stmt_ip_created_idx FROM @sql_ip_created_idx;
EXECUTE stmt_ip_created_idx;
DEALLOCATE PREPARE stmt_ip_created_idx;

SET @has_cleanup_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = 'u170017077_mantenimiento'
      AND table_name = 'password_reset_tokens'
      AND index_name = 'idx_password_reset_cleanup'
);
SET @sql_cleanup_idx := IF(
    @has_cleanup_idx = 0,
    'ALTER TABLE `u170017077_mantenimiento`.`password_reset_tokens` ADD KEY `idx_password_reset_cleanup` (`expires_at`, `used_at`, `revoked_at`)',
    'SELECT ''idx_password_reset_cleanup ya existe'' AS message'
);
PREPARE stmt_cleanup_idx FROM @sql_cleanup_idx;
EXECUTE stmt_cleanup_idx;
DEALLOCATE PREPARE stmt_cleanup_idx;

INSERT INTO `u170017077_mantenimiento`.`schema_migrations` (`version`, `name`)
VALUES ('16', 'password_reset_token_hardening')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- VALIDACIONES: schema explícito para no depender del contexto de phpMyAdmin.
SELECT
    CASE WHEN COUNT(*) = 3 THEN 'OK' ELSE 'ERROR' END AS password_reset_columns_validation,
    COUNT(*) AS columns_found
FROM information_schema.columns
WHERE table_schema = 'u170017077_mantenimiento'
  AND table_name = 'password_reset_tokens'
  AND column_name IN ('revoked_at', 'requested_ip', 'user_agent');

SELECT
    CASE WHEN column_type = 'char(64)' AND is_nullable = 'NO' THEN 'OK' ELSE 'ERROR' END AS token_hash_definition_validation,
    column_type,
    is_nullable
FROM information_schema.columns
WHERE table_schema = 'u170017077_mantenimiento'
  AND table_name = 'password_reset_tokens'
  AND column_name = 'token_hash';

SELECT
    CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'ERROR' END AS token_hash_unique_validation,
    COUNT(*) AS indexes_found
FROM (
    SELECT index_name
    FROM information_schema.statistics
    WHERE table_schema = 'u170017077_mantenimiento'
      AND table_name = 'password_reset_tokens'
      AND index_name = 'uq_password_reset_token_hash'
      AND non_unique = 0
    GROUP BY index_name
) x;

SELECT
    CASE WHEN COUNT(*) = 3 THEN 'OK' ELSE 'ERROR' END AS hardening_indexes_validation,
    COUNT(*) AS indexes_found
FROM (
    SELECT index_name
    FROM information_schema.statistics
    WHERE table_schema = 'u170017077_mantenimiento'
      AND table_name = 'password_reset_tokens'
      AND index_name IN (
          'idx_password_reset_user_created',
          'idx_password_reset_ip_created',
          'idx_password_reset_cleanup'
      )
    GROUP BY index_name
) x;

SELECT
    CASE WHEN COUNT(*) = 1 THEN 'OK' ELSE 'ERROR' END AS password_reset_fk_validation,
    COUNT(*) AS foreign_keys_found
FROM information_schema.key_column_usage
WHERE constraint_schema = 'u170017077_mantenimiento'
  AND table_name = 'password_reset_tokens'
  AND constraint_name = 'fk_password_reset_user'
  AND column_name = 'user_id'
  AND referenced_table_name = 'users'
  AND referenced_column_name = 'id';

SELECT COUNT(*) AS password_reset_tokens_total
FROM `u170017077_mantenimiento`.`password_reset_tokens`;

SELECT version, name, applied_at
FROM `u170017077_mantenimiento`.`schema_migrations`
WHERE version = '16';
