-- Plataforma de Gestión de Mantenimiento
-- Migración 08: tokens de verificación de correo electrónico
-- Base de datos: u170017077_mantenimiento
-- Compatible con MariaDB / phpMyAdmin
--
-- Propósito:
--   - Verificar el correo de usuarios registrados públicamente.
--   - Conservar la ubicación de origen cuando el registro comenzó desde un QR.
--   - Permitir revocar tokens anteriores y controlar reenvíos desde backend.
--
-- Seguridad:
--   - token_hash almacena SHA-256 del token; nunca se guarda el token original.
--   - No contiene restricciones CHECK para maximizar compatibilidad.
--   - Las reglas de expiración, uso y revocación se validarán también en PHP.

USE `u170017077_mantenimiento`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS `email_verification_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `location_id` SMALLINT UNSIGNED NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `revoked_at` DATETIME NULL,
  `requested_ip` VARCHAR(45) NULL,
  `user_agent` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  UNIQUE KEY `uq_email_verification_token_hash` (`token_hash`),

  KEY `idx_email_verification_user_created`
    (`user_id`, `created_at`),

  KEY `idx_email_verification_user_expiry`
    (`user_id`, `expires_at`),

  KEY `idx_email_verification_location`
    (`location_id`),

  KEY `idx_email_verification_ip_created`
    (`requested_ip`, `created_at`),

  KEY `idx_email_verification_cleanup`
    (`expires_at`, `used_at`, `revoked_at`),

  CONSTRAINT `fk_email_verification_user`
    FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,

  CONSTRAINT `fk_email_verification_location`
    FOREIGN KEY (`location_id`)
    REFERENCES `locations` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`, `name`)
VALUES ('08', 'email_verification_tokens')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`);

-- Validación rápida para phpMyAdmin.
SELECT
  CASE
    WHEN COUNT(*) = 1 THEN 'OK'
    ELSE 'ERROR'
  END AS `table_validation`,
  COUNT(*) AS `tables_found`
FROM `information_schema`.`tables`
WHERE `table_schema` = DATABASE()
  AND `table_name` = 'email_verification_tokens';

SELECT
  `version`,
  `name`,
  `applied_at`
FROM `schema_migrations`
WHERE `version` = '08';

SHOW CREATE TABLE `email_verification_tokens`;
