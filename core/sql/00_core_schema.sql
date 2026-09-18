-- EU Tools Core
-- Migration 00: core schema
-- Database: u170017077_tools
-- Compatible with MariaDB / phpMyAdmin
--
-- Principles:
--   - Global identity belongs to Tools Core.
--   - Authorization is scoped by application.
--   - One active role per user per application.
--   - Passwords/tokens store hashes only.
--   - No maintenance-domain foreign keys exist in Core.

USE `u170017077_tools`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- =========================================================
-- 1. Migration registry
-- =========================================================

CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `version` VARCHAR(20) NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`version`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 2. Applications catalog
-- =========================================================

CREATE TABLE IF NOT EXISTS `applications` (
    `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(60) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `description` VARCHAR(255) NULL,
    `base_path` VARCHAR(160) NOT NULL,
    `show_in_hub` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_applications_code` (`code`),
    UNIQUE KEY `uq_applications_base_path` (`base_path`),
    KEY `idx_applications_hub` (`show_in_hub`, `is_active`, `sort_order`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 3. Global identities
-- =========================================================

CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `first_name` VARCHAR(80) NOT NULL,
    `last_name` VARCHAR(120) NOT NULL,
    `email` VARCHAR(190) NOT NULL,
    `password_hash` VARCHAR(255) NULL,
    `status` ENUM('active','inactive','blocked','pending','invited') NOT NULL DEFAULT 'pending',
    `must_change_password` TINYINT(1) NOT NULL DEFAULT 1,
    `failed_login_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until` DATETIME NULL,
    `last_login_at` DATETIME NULL,
    `email_verified_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deactivated_at` DATETIME NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_status` (`status`),
    KEY `idx_users_locked_until` (`locked_until`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 4. Persistent revocable sessions
-- =========================================================

CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `session_hash` CHAR(64) NOT NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(500) NULL,
    `last_activity_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME NOT NULL,
    `revoked_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_sessions_hash` (`session_hash`),
    KEY `idx_user_sessions_user` (`user_id`, `expires_at`),
    KEY `idx_user_sessions_active` (`revoked_at`, `expires_at`),

    CONSTRAINT `fk_user_sessions_user`
        FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 5. Email verification
--    Global token: no Maintenance location dependency.
-- =========================================================

CREATE TABLE IF NOT EXISTS `email_verification_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `revoked_at` DATETIME NULL,
    `requested_ip` VARCHAR(45) NULL,
    `user_agent` VARCHAR(500) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email_verification_token_hash` (`token_hash`),
    KEY `idx_email_verification_user_created` (`user_id`, `created_at`),
    KEY `idx_email_verification_user_expiry` (`user_id`, `expires_at`),
    KEY `idx_email_verification_ip_created` (`requested_ip`, `created_at`),
    KEY `idx_email_verification_cleanup` (`expires_at`, `used_at`, `revoked_at`),

    CONSTRAINT `fk_email_verification_user`
        FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 6. Password reset
-- =========================================================

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `revoked_at` DATETIME NULL,
    `requested_ip` VARCHAR(45) NULL,
    `user_agent` VARCHAR(500) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_password_reset_token_hash` (`token_hash`),
    KEY `idx_password_reset_user` (`user_id`, `expires_at`),
    KEY `idx_password_reset_user_created` (`user_id`, `created_at`),
    KEY `idx_password_reset_ip_created` (`requested_ip`, `created_at`),
    KEY `idx_password_reset_cleanup` (`expires_at`, `used_at`, `revoked_at`),

    CONSTRAINT `fk_password_reset_user`
        FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 7. Administrative invitations
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
    UNIQUE KEY `uq_user_invitation_token_hash` (`token_hash`),
    KEY `idx_user_invitation_user_created` (`user_id`, `created_at`),
    KEY `idx_user_invitation_user_expiry` (`user_id`, `expires_at`),
    KEY `idx_user_invitation_creator_created` (`created_by_user_id`, `created_at`),
    KEY `idx_user_invitation_cleanup` (`expires_at`, `used_at`, `revoked_at`),

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
-- 8. Application-scoped roles
-- =========================================================

CREATE TABLE IF NOT EXISTS `application_roles` (
    `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `application_id` SMALLINT UNSIGNED NOT NULL,
    `code` VARCHAR(60) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `description` VARCHAR(255) NULL,
    `is_system` TINYINT(1) NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_application_roles_app_code` (`application_id`, `code`),
    UNIQUE KEY `uq_application_roles_id_app` (`id`, `application_id`),
    KEY `idx_application_roles_active` (`application_id`, `is_active`),

    CONSTRAINT `fk_application_roles_application`
        FOREIGN KEY (`application_id`)
        REFERENCES `applications` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 9. Application-scoped permissions / scopes
-- =========================================================

CREATE TABLE IF NOT EXISTS `permissions` (
    `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `application_id` SMALLINT UNSIGNED NOT NULL,
    `code` VARCHAR(120) NOT NULL,
    `module` VARCHAR(60) NOT NULL,
    `name` VARCHAR(140) NOT NULL,
    `description` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_permissions_app_code` (`application_id`, `code`),
    UNIQUE KEY `uq_permissions_id_app` (`id`, `application_id`),
    KEY `idx_permissions_module` (`application_id`, `module`),

    CONSTRAINT `fk_permissions_application`
        FOREIGN KEY (`application_id`)
        REFERENCES `applications` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 10. Role -> permission matrix
--     application_id is repeated intentionally so the DB can
--     guarantee that role and permission belong to the same app.
-- =========================================================

CREATE TABLE IF NOT EXISTS `role_permissions` (
    `application_id` SMALLINT UNSIGNED NOT NULL,
    `role_id` SMALLINT UNSIGNED NOT NULL,
    `permission_id` SMALLINT UNSIGNED NOT NULL,
    `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`role_id`, `permission_id`),
    KEY `idx_role_permissions_role_app` (`role_id`, `application_id`),
    KEY `idx_role_permissions_permission` (`permission_id`, `application_id`),
    KEY `idx_role_permissions_application` (`application_id`),

    CONSTRAINT `fk_role_permissions_role_app`
        FOREIGN KEY (`role_id`, `application_id`)
        REFERENCES `application_roles` (`id`, `application_id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT `fk_role_permissions_permission_app`
        FOREIGN KEY (`permission_id`, `application_id`)
        REFERENCES `permissions` (`id`, `application_id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 11. User access by application
--     Contract: at most one role per user per application.
--     revoked_at NULL means the assignment is active.
-- =========================================================

CREATE TABLE IF NOT EXISTS `user_application_roles` (
    `user_id` BIGINT UNSIGNED NOT NULL,
    `application_id` SMALLINT UNSIGNED NOT NULL,
    `role_id` SMALLINT UNSIGNED NOT NULL,
    `assigned_by_user_id` BIGINT UNSIGNED NULL,
    `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `revoked_by_user_id` BIGINT UNSIGNED NULL,
    `revoked_at` DATETIME NULL,

    PRIMARY KEY (`user_id`, `application_id`),
    KEY `idx_user_application_roles_role` (`role_id`, `application_id`),
    KEY `idx_user_application_roles_assigned_by` (`assigned_by_user_id`),
    KEY `idx_user_application_roles_revoked_by` (`revoked_by_user_id`),
    KEY `idx_user_application_roles_active` (`application_id`, `revoked_at`, `role_id`),

    CONSTRAINT `fk_user_application_roles_user`
        FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT `fk_user_application_roles_application`
        FOREIGN KEY (`application_id`)
        REFERENCES `applications` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT `fk_user_application_roles_role_app`
        FOREIGN KEY (`role_id`, `application_id`)
        REFERENCES `application_roles` (`id`, `application_id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT `fk_user_application_roles_assigned_by`
        FOREIGN KEY (`assigned_by_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT `fk_user_application_roles_revoked_by`
        FOREIGN KEY (`revoked_by_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 12. Core audit log
--     application_id indicates the app affected by a Core event.
--     No Maintenance ticket dependency exists here.
-- =========================================================

CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor_user_id` BIGINT UNSIGNED NULL,
    `application_id` SMALLINT UNSIGNED NULL,
    `action_code` VARCHAR(120) NOT NULL,
    `entity_type` VARCHAR(80) NOT NULL,
    `entity_id` BIGINT UNSIGNED NULL,
    `request_id` CHAR(36) NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(500) NULL,
    `old_values_json` LONGTEXT NULL,
    `new_values_json` LONGTEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_audit_actor` (`actor_user_id`, `created_at`),
    KEY `idx_audit_application` (`application_id`, `created_at`),
    KEY `idx_audit_entity` (`entity_type`, `entity_id`, `created_at`),
    KEY `idx_audit_action` (`action_code`, `created_at`),
    KEY `idx_audit_request` (`request_id`),

    CONSTRAINT `fk_audit_actor`
        FOREIGN KEY (`actor_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT `fk_audit_application`
        FOREIGN KEY (`application_id`)
        REFERENCES `applications` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 13. Core email delivery log
-- =========================================================

CREATE TABLE IF NOT EXISTS `email_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `application_id` SMALLINT UNSIGNED NULL,
    `recipient_user_id` BIGINT UNSIGNED NULL,
    `recipient_email` VARCHAR(190) NOT NULL,
    `event_code` VARCHAR(100) NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `status` ENUM('sent','failed') NOT NULL,
    `error_message` TEXT NULL,
    `sent_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_email_log_application` (`application_id`, `created_at`),
    KEY `idx_email_log_recipient_user` (`recipient_user_id`, `created_at`),
    KEY `idx_email_log_event` (`event_code`, `created_at`),
    KEY `idx_email_log_status` (`status`, `created_at`),
    KEY `idx_email_log_recipient_email` (`recipient_email`, `created_at`),

    CONSTRAINT `fk_email_log_application`
        FOREIGN KEY (`application_id`)
        REFERENCES `applications` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT `fk_email_log_recipient_user`
        FOREIGN KEY (`recipient_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 14. Register migration
-- =========================================================

INSERT INTO `schema_migrations` (`version`, `name`)
VALUES ('00', 'core_schema')
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);

-- =========================================================
-- VALIDATION
-- =========================================================

SELECT
    COUNT(*) AS `core_tables_found`
FROM `information_schema`.`tables`
WHERE `table_schema` = DATABASE()
  AND `table_name` IN (
      'schema_migrations',
      'applications',
      'users',
      'user_sessions',
      'email_verification_tokens',
      'password_reset_tokens',
      'user_invitation_tokens',
      'application_roles',
      'permissions',
      'role_permissions',
      'user_application_roles',
      'audit_log',
      'email_log'
  );

SELECT
    `version`,
    `name`,
    `applied_at`
FROM `schema_migrations`
WHERE `version` = '00';
