-- EU Tools Core
-- Migration 03: Maintenance domain
-- Database: u170017077_tools
--
-- Purpose:
--   Rebuild only the operational Maintenance domain inside EU Tools.
--   No legacy users, sessions, tokens, roles, role assignments, audit rows,
--   email rows or Maintenance schema history are imported.
--
-- Compatibility:
--   Domain table names intentionally remain equal to Maintenance V1 so the
--   business services can be migrated with the smallest possible SQL surface.
--
-- Core-owned tables reused by this domain:
--   users
--   permissions
--   audit_log
--   email_log
--
-- Important:
--   ticket_status_transitions resolves permissions by application + code.
--   No legacy permission IDs are reused.

USE `u170017077_tools`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @app_maintenance := (
    SELECT `id`
    FROM `applications`
    WHERE `code` = 'maintenance'
    LIMIT 1
);

-- Fail-fast aid: validation at the end must show maintenance_app_id != NULL.

-- =========================================================
-- 1. Maintenance catalogs
-- =========================================================

CREATE TABLE IF NOT EXISTS `approval_statuses` (
    `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(30) NOT NULL,
    `name` VARCHAR(80) NOT NULL,
    `is_terminal` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_approval_statuses_code` (`code`),
    KEY `idx_approval_statuses_order` (`sort_order`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_priorities` (
    `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(30) NOT NULL,
    `name` VARCHAR(50) NOT NULL,
    `weight` SMALLINT UNSIGNED NOT NULL,
    `color_reference` VARCHAR(20) NULL,
    `response_target_minutes` INT UNSIGNED NULL,
    `resolution_target_minutes` INT UNSIGNED NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ticket_priorities_code` (`code`),
    UNIQUE KEY `uq_ticket_priorities_weight` (`weight`),
    KEY `idx_ticket_priorities_active` (`is_active`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_statuses` (
    `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(40) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `lifecycle_group` VARCHAR(30) NOT NULL,
    `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `is_terminal` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ticket_statuses_code` (`code`),
    KEY `idx_ticket_statuses_group` (`lifecycle_group`, `sort_order`),
    KEY `idx_ticket_statuses_active` (`is_active`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 2. Maintenance configuration
-- =========================================================

CREATE TABLE IF NOT EXISTS `folio_sequences` (
    `sequence_code` VARCHAR(30) NOT NULL,
    `sequence_year` SMALLINT UNSIGNED NOT NULL,
    `last_value` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`sequence_code`, `sequence_year`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_settings` (
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` LONGTEXT NOT NULL,
    `value_type` ENUM('string','integer','boolean','json') NOT NULL DEFAULT 'string',
    `description` VARCHAR(255) NULL,
    `is_public` TINYINT(1) NOT NULL DEFAULT 0,
    `updated_by_user_id` BIGINT UNSIGNED NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`setting_key`),
    KEY `idx_system_settings_public` (`is_public`),
    KEY `idx_system_settings_updated_by` (`updated_by_user_id`),

    CONSTRAINT `fk_system_settings_updated_by`
        FOREIGN KEY (`updated_by_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `locations` (
    `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `description` VARCHAR(255) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `default_supervisor_user_id` BIGINT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_locations_code` (`code`),
    KEY `idx_locations_active_order` (`is_active`, `sort_order`),
    KEY `idx_locations_default_supervisor` (`default_supervisor_user_id`),

    CONSTRAINT `fk_locations_default_supervisor_user`
        FOREIGN KEY (`default_supervisor_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 3. Tickets
-- =========================================================

CREATE TABLE IF NOT EXISTS `tickets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `folio` VARCHAR(40) NOT NULL,
    `title` VARCHAR(180) NOT NULL,
    `description` TEXT NOT NULL,
    `reported_by_user_id` BIGINT UNSIGNED NOT NULL,
    `location_id` SMALLINT UNSIGNED NOT NULL,
    `specific_location` VARCHAR(255) NOT NULL,
    `priority_id` SMALLINT UNSIGNED NOT NULL,
    `current_status_id` SMALLINT UNSIGNED NOT NULL,
    `processing_route` VARCHAR(30) NULL COMMENT 'direct|authorization_required',
    `supervisor_user_id` BIGINT UNSIGNED NULL,
    `director_user_id` BIGINT UNSIGNED NULL,
    `action_owner_user_id` BIGINT UNSIGNED NULL,
    `resolution_summary` TEXT NULL,
    `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `authorized_at` DATETIME NULL,
    `started_at` DATETIME NULL,
    `completed_at` DATETIME NULL,
    `closed_at` DATETIME NULL,
    `cancelled_at` DATETIME NULL,
    `action_due_at` DATETIME NULL,
    `row_version` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tickets_folio` (`folio`),
    KEY `idx_tickets_status_priority` (`current_status_id`, `priority_id`),
    KEY `idx_tickets_reporter_created` (`reported_by_user_id`, `created_at`),
    KEY `idx_tickets_supervisor_status` (`supervisor_user_id`, `current_status_id`, `created_at`),
    KEY `idx_tickets_director_status` (`director_user_id`, `current_status_id`, `created_at`),
    KEY `idx_tickets_action_owner` (`action_owner_user_id`, `current_status_id`, `action_due_at`),
    KEY `idx_tickets_location_created` (`location_id`, `created_at`),
    KEY `idx_tickets_updated` (`updated_at`),
    KEY `idx_tickets_priority` (`priority_id`),
    KEY `idx_tickets_processing_route` (`processing_route`, `current_status_id`, `updated_at`),

    CONSTRAINT `fk_tickets_reporter`
        FOREIGN KEY (`reported_by_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_tickets_location`
        FOREIGN KEY (`location_id`)
        REFERENCES `locations` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_tickets_priority`
        FOREIGN KEY (`priority_id`)
        REFERENCES `ticket_priorities` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_tickets_current_status`
        FOREIGN KEY (`current_status_id`)
        REFERENCES `ticket_statuses` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_tickets_supervisor`
        FOREIGN KEY (`supervisor_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT `fk_tickets_director`
        FOREIGN KEY (`director_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT `fk_tickets_action_owner`
        FOREIGN KEY (`action_owner_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_comments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ticket_id` BIGINT UNSIGNED NOT NULL,
    `author_user_id` BIGINT UNSIGNED NOT NULL,
    `body` TEXT NOT NULL,
    `comment_type` ENUM('general','follow_up','approval','system') NOT NULL DEFAULT 'general',
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL,
    `deleted_by_user_id` BIGINT UNSIGNED NULL,

    PRIMARY KEY (`id`),
    KEY `idx_ticket_comments_ticket` (`ticket_id`, `created_at`),
    KEY `idx_ticket_comments_author` (`author_user_id`, `created_at`),
    KEY `idx_ticket_comments_deleted` (`deleted_at`),
    KEY `idx_ticket_comments_deleted_by` (`deleted_by_user_id`),

    CONSTRAINT `fk_ticket_comments_ticket`
        FOREIGN KEY (`ticket_id`)
        REFERENCES `tickets` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_ticket_comments_author`
        FOREIGN KEY (`author_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_ticket_comments_deleted_by`
        FOREIGN KEY (`deleted_by_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_attachments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ticket_id` BIGINT UNSIGNED NOT NULL,
    `comment_id` BIGINT UNSIGNED NULL,
    `uploaded_by_user_id` BIGINT UNSIGNED NOT NULL,
    `attachment_type` ENUM('evidence','quotation','completion_evidence','other') NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `stored_name` VARCHAR(255) NOT NULL,
    `storage_disk` VARCHAR(30) NOT NULL DEFAULT 'local',
    `storage_path` VARCHAR(500) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `extension` VARCHAR(15) NOT NULL,
    `size_bytes` BIGINT UNSIGNED NOT NULL,
    `sha256_hash` CHAR(64) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL,
    `deleted_by_user_id` BIGINT UNSIGNED NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ticket_attachments_stored_name` (`stored_name`),
    KEY `idx_ticket_attachments_ticket_type` (`ticket_id`, `attachment_type`, `created_at`),
    KEY `idx_ticket_attachments_comment` (`comment_id`),
    KEY `idx_ticket_attachments_hash` (`sha256_hash`),
    KEY `idx_ticket_attachments_deleted` (`deleted_at`),
    KEY `idx_ticket_attachments_uploaded_by` (`uploaded_by_user_id`),
    KEY `idx_ticket_attachments_deleted_by` (`deleted_by_user_id`),

    CONSTRAINT `fk_ticket_attachments_ticket`
        FOREIGN KEY (`ticket_id`)
        REFERENCES `tickets` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_ticket_attachments_comment`
        FOREIGN KEY (`comment_id`)
        REFERENCES `ticket_comments` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT `fk_ticket_attachments_uploaded_by`
        FOREIGN KEY (`uploaded_by_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_ticket_attachments_deleted_by`
        FOREIGN KEY (`deleted_by_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_quotations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ticket_id` BIGINT UNSIGNED NOT NULL,
    `attachment_id` BIGINT UNSIGNED NOT NULL,
    `previous_quotation_id` BIGINT UNSIGNED NULL,
    `version_number` SMALLINT UNSIGNED NOT NULL,
    `supplier_name` VARCHAR(180) NOT NULL,
    `reference_number` VARCHAR(100) NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `currency` CHAR(3) NOT NULL DEFAULT 'MXN',
    `valid_until` DATE NULL COMMENT 'Fecha de vigencia indicada por el proveedor',
    `description` TEXT NULL,
    `status` ENUM('current','replaced','withdrawn','approved') NOT NULL DEFAULT 'current',
    `is_current` TINYINT(1) NOT NULL DEFAULT 1,
    `uploaded_by_user_id` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ticket_quotation_version` (`ticket_id`, `version_number`),
    UNIQUE KEY `uq_ticket_quotation_attachment` (`attachment_id`),
    KEY `idx_ticket_quotations_current` (`ticket_id`, `is_current`),
    KEY `idx_ticket_quotations_status` (`status`),
    KEY `idx_ticket_quotations_previous` (`previous_quotation_id`),
    KEY `idx_ticket_quotations_uploaded_by` (`uploaded_by_user_id`),
    KEY `idx_ticket_quotations_validity` (`ticket_id`, `is_current`, `valid_until`),

    CONSTRAINT `fk_ticket_quotations_ticket`
        FOREIGN KEY (`ticket_id`)
        REFERENCES `tickets` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_ticket_quotations_attachment`
        FOREIGN KEY (`attachment_id`)
        REFERENCES `ticket_attachments` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_ticket_quotations_previous`
        FOREIGN KEY (`previous_quotation_id`)
        REFERENCES `ticket_quotations` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT `fk_ticket_quotations_uploaded_by`
        FOREIGN KEY (`uploaded_by_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_approval_requests` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ticket_id` BIGINT UNSIGNED NOT NULL,
    `quotation_id` BIGINT UNSIGNED NOT NULL,
    `status_id` SMALLINT UNSIGNED NOT NULL,
    `requested_by_user_id` BIGINT UNSIGNED NOT NULL,
    `approver_user_id` BIGINT UNSIGNED NOT NULL,
    `decided_by_user_id` BIGINT UNSIGNED NULL,
    `supersedes_request_id` BIGINT UNSIGNED NULL,
    `request_comment` TEXT NOT NULL,
    `decision_comment` TEXT NULL,
    `requested_amount` DECIMAL(14,2) NOT NULL,
    `approved_amount` DECIMAL(14,2) NULL,
    `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `responded_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_approval_requests_ticket` (`ticket_id`, `requested_at`),
    KEY `idx_approval_requests_approver_status` (`approver_user_id`, `status_id`, `requested_at`),
    KEY `idx_approval_requests_status` (`status_id`, `requested_at`),
    KEY `idx_approval_requests_quotation` (`quotation_id`),
    KEY `idx_approval_requests_supersedes` (`supersedes_request_id`),
    KEY `idx_approval_requests_requested_by` (`requested_by_user_id`),
    KEY `idx_approval_requests_decided_by` (`decided_by_user_id`),

    CONSTRAINT `fk_approval_requests_ticket`
        FOREIGN KEY (`ticket_id`)
        REFERENCES `tickets` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_approval_requests_quotation`
        FOREIGN KEY (`quotation_id`)
        REFERENCES `ticket_quotations` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_approval_requests_status`
        FOREIGN KEY (`status_id`)
        REFERENCES `approval_statuses` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_approval_requests_requested_by`
        FOREIGN KEY (`requested_by_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_approval_requests_approver`
        FOREIGN KEY (`approver_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_approval_requests_decided_by`
        FOREIGN KEY (`decided_by_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT `fk_approval_requests_supersedes`
        FOREIGN KEY (`supersedes_request_id`)
        REFERENCES `ticket_approval_requests` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_assignment_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ticket_id` BIGINT UNSIGNED NOT NULL,
    `assignment_type` ENUM('supervisor','director','action_owner') NOT NULL,
    `previous_user_id` BIGINT UNSIGNED NULL,
    `new_user_id` BIGINT UNSIGNED NULL,
    `assigned_by_user_id` BIGINT UNSIGNED NOT NULL,
    `comment` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_assignment_history_ticket` (`ticket_id`, `created_at`),
    KEY `idx_assignment_history_new_user` (`new_user_id`, `created_at`),
    KEY `idx_assignment_history_assigned_by` (`assigned_by_user_id`, `created_at`),
    KEY `idx_assignment_history_previous_user` (`previous_user_id`),

    CONSTRAINT `fk_assignment_history_ticket`
        FOREIGN KEY (`ticket_id`)
        REFERENCES `tickets` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_assignment_history_previous_user`
        FOREIGN KEY (`previous_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT `fk_assignment_history_new_user`
        FOREIGN KEY (`new_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT `fk_assignment_history_assigned_by`
        FOREIGN KEY (`assigned_by_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_status_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ticket_id` BIGINT UNSIGNED NOT NULL,
    `from_status_id` SMALLINT UNSIGNED NULL,
    `to_status_id` SMALLINT UNSIGNED NOT NULL,
    `changed_by_user_id` BIGINT UNSIGNED NOT NULL,
    `comment` TEXT NULL,
    `change_source` ENUM('user','system','migration') NOT NULL DEFAULT 'user',
    `metadata_json` LONGTEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_status_history_ticket` (`ticket_id`, `created_at`),
    KEY `idx_status_history_user` (`changed_by_user_id`, `created_at`),
    KEY `idx_status_history_to_status` (`to_status_id`, `created_at`),
    KEY `idx_status_history_from_status` (`from_status_id`),

    CONSTRAINT `fk_status_history_ticket`
        FOREIGN KEY (`ticket_id`)
        REFERENCES `tickets` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_status_history_from_status`
        FOREIGN KEY (`from_status_id`)
        REFERENCES `ticket_statuses` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_status_history_to_status`
        FOREIGN KEY (`to_status_id`)
        REFERENCES `ticket_statuses` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_status_history_changed_by`
        FOREIGN KEY (`changed_by_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `ticket_id` BIGINT UNSIGNED NULL,
    `actor_user_id` BIGINT UNSIGNED NULL,
    `type` VARCHAR(80) NOT NULL,
    `title` VARCHAR(180) NOT NULL,
    `message` TEXT NOT NULL,
    `action_url` VARCHAR(500) NULL,
    `deduplication_key` VARCHAR(190) NULL,
    `read_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notifications_deduplication_key` (`deduplication_key`),
    KEY `idx_notifications_user_read` (`user_id`, `read_at`, `created_at`),
    KEY `idx_notifications_ticket` (`ticket_id`, `created_at`),
    KEY `idx_notifications_type` (`type`, `created_at`),
    KEY `idx_notifications_actor` (`actor_user_id`),

    CONSTRAINT `fk_notifications_user`
        FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_notifications_ticket`
        FOREIGN KEY (`ticket_id`)
        REFERENCES `tickets` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_notifications_actor`
        FOREIGN KEY (`actor_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_status_transitions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `from_status_id` SMALLINT UNSIGNED NOT NULL,
    `to_status_id` SMALLINT UNSIGNED NOT NULL,
    `required_permission_id` SMALLINT UNSIGNED NOT NULL,
    `requires_comment` TINYINT(1) NOT NULL DEFAULT 0,
    `requires_quotation` TINYINT(1) NOT NULL DEFAULT 0,
    `requires_supervisor` TINYINT(1) NOT NULL DEFAULT 0,
    `requires_director` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ticket_status_transition` (`from_status_id`, `to_status_id`),
    KEY `idx_ticket_status_transitions_permission` (`required_permission_id`),
    KEY `idx_ticket_status_transitions_to_status` (`to_status_id`),

    CONSTRAINT `fk_transition_from_status`
        FOREIGN KEY (`from_status_id`)
        REFERENCES `ticket_statuses` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_transition_to_status`
        FOREIGN KEY (`to_status_id`)
        REFERENCES `ticket_statuses` (`id`)
        ON UPDATE CASCADE,

    CONSTRAINT `fk_transition_permission`
        FOREIGN KEY (`required_permission_id`)
        REFERENCES `permissions` (`id`)
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 4. Catalog seeds
--    No legacy primary keys are imported.
-- =========================================================

INSERT INTO `approval_statuses`
    (`code`, `name`, `is_terminal`, `sort_order`)
VALUES
    ('pending', 'Pendiente', 0, 10),
    ('approved', 'Autorizada', 1, 20),
    ('rejected', 'Rechazada', 1, 30),
    ('changes_requested', 'Correcciones solicitadas', 1, 40),
    ('cancelled', 'Cancelada', 1, 50)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `is_terminal` = VALUES(`is_terminal`),
    `sort_order` = VALUES(`sort_order`);

INSERT INTO `ticket_priorities`
    (`code`, `name`, `weight`, `color_reference`, `response_target_minutes`, `resolution_target_minutes`, `is_active`)
VALUES
    ('low', 'Baja', 10, 'neutral', NULL, NULL, 1),
    ('medium', 'Media', 20, 'info', NULL, NULL, 1),
    ('high', 'Alta', 30, 'warning', NULL, NULL, 1),
    ('urgent', 'Urgente', 40, 'danger', NULL, NULL, 1)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `weight` = VALUES(`weight`),
    `color_reference` = VALUES(`color_reference`),
    `response_target_minutes` = VALUES(`response_target_minutes`),
    `resolution_target_minutes` = VALUES(`resolution_target_minutes`),
    `is_active` = VALUES(`is_active`);

INSERT INTO `ticket_statuses`
    (`code`, `name`, `lifecycle_group`, `sort_order`, `is_terminal`, `is_active`)
VALUES
    ('new', 'Nuevo', 'pending', 10, 0, 1),
    ('under_review', 'En revisión', 'supervisor', 20, 0, 1),
    ('quotation_pending', 'Cotización pendiente', 'supervisor', 30, 0, 1),
    ('authorization_pending', 'Pendiente de autorización', 'direction', 40, 0, 1),
    ('changes_requested', 'Correcciones solicitadas', 'supervisor', 50, 0, 1),
    ('authorized', 'Autorizado', 'execution', 60, 0, 1),
    ('in_progress', 'En proceso', 'execution', 70, 0, 1),
    ('completed', 'Trabajo terminado', 'validation', 80, 0, 1),
    ('closed', 'Cerrado', 'final', 90, 1, 1),
    ('rejected', 'Rechazado', 'final', 100, 1, 1),
    ('cancelled', 'Cancelado', 'final', 110, 1, 1)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `lifecycle_group` = VALUES(`lifecycle_group`),
    `sort_order` = VALUES(`sort_order`),
    `is_terminal` = VALUES(`is_terminal`),
    `is_active` = VALUES(`is_active`);

INSERT INTO `locations`
    (`code`, `name`, `description`, `is_active`, `sort_order`, `default_supervisor_user_id`)
VALUES
    ('showroom_naos', 'Showroom NAOS', NULL, 1, 10, NULL),
    ('showroom_the_wavve', 'Showroom THE WAVVE', NULL, 1, 20, NULL),
    ('showroom_estrategia_urbana', 'Showroom Estrategia Urbana', NULL, 1, 30, NULL),
    ('corporativo_estrategia_urbana', 'Corporativo Estrategia Urbana', NULL, 1, 40, NULL)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `is_active` = VALUES(`is_active`),
    `sort_order` = VALUES(`sort_order`);

INSERT INTO `folio_sequences`
    (`sequence_code`, `sequence_year`, `last_value`)
VALUES
    ('MNT', 2026, 0)
ON DUPLICATE KEY UPDATE
    `sequence_code` = VALUES(`sequence_code`);

INSERT INTO `system_settings`
    (`setting_key`, `setting_value`, `value_type`, `description`, `is_public`)
VALUES
    ('pagination.default_page_size', '20', 'integer', 'Registros predeterminados por página.', 1),
    ('pagination.max_page_size', '100', 'integer', 'Límite máximo de registros por página.', 0),
    ('ticket.default_priority', 'medium', 'string', 'Prioridad predeterminada para nuevos tickets.', 0),
    ('ticket.folio_prefix', 'MNT', 'string', 'Prefijo de los folios de mantenimiento.', 0),
    ('upload.evidence.max_files', '8', 'integer', 'Cantidad máxima de evidencias por operación.', 1),
    ('upload.evidence.max_size_mb', '8', 'integer', 'Tamaño máximo por imagen en MB.', 1),
    ('upload.quotation.max_size_mb', '15', 'integer', 'Tamaño máximo de una cotización PDF en MB.', 1)
ON DUPLICATE KEY UPDATE
    `setting_value` = VALUES(`setting_value`),
    `value_type` = VALUES(`value_type`),
    `description` = VALUES(`description`),
    `is_public` = VALUES(`is_public`);

-- =========================================================
-- 5. Workflow transitions
--    Permission IDs are resolved from the current Core catalog.
-- =========================================================

INSERT INTO `ticket_status_transitions` (
    `from_status_id`,
    `to_status_id`,
    `required_permission_id`,
    `requires_comment`,
    `requires_quotation`,
    `requires_supervisor`,
    `requires_director`,
    `is_active`
)
SELECT
    fs.`id`,
    ts.`id`,
    p.`id`,
    rules.`requires_comment`,
    rules.`requires_quotation`,
    rules.`requires_supervisor`,
    rules.`requires_director`,
    1
FROM (
    SELECT 'authorization_pending' AS `from_code`, 'changes_requested' AS `to_code`, 'ticket.request_changes' AS `permission_code`, 1 AS `requires_comment`, 1 AS `requires_quotation`, 0 AS `requires_supervisor`, 1 AS `requires_director`
    UNION ALL
    SELECT 'authorization_pending', 'rejected', 'ticket.reject', 1, 1, 0, 1
    UNION ALL
    SELECT 'authorization_pending', 'authorized', 'ticket.authorize', 1, 1, 0, 1
    UNION ALL
    SELECT 'authorized', 'cancelled', 'ticket.change_status', 1, 0, 1, 0
    UNION ALL
    SELECT 'authorized', 'in_progress', 'ticket.change_status', 0, 1, 1, 0
    UNION ALL
    SELECT 'changes_requested', 'cancelled', 'ticket.change_status', 1, 0, 1, 0
    UNION ALL
    SELECT 'changes_requested', 'authorization_pending', 'ticket.request_authorization', 1, 1, 1, 1
    UNION ALL
    SELECT 'changes_requested', 'quotation_pending', 'ticket.change_status', 0, 0, 1, 0
    UNION ALL
    SELECT 'completed', 'closed', 'ticket.change_status', 0, 0, 0, 0
    UNION ALL
    SELECT 'in_progress', 'completed', 'ticket.change_status', 1, 0, 1, 0
    UNION ALL
    SELECT 'new', 'cancelled', 'ticket.change_status', 1, 0, 0, 0
    UNION ALL
    SELECT 'new', 'under_review', 'ticket.change_status', 0, 0, 1, 0
    UNION ALL
    SELECT 'quotation_pending', 'cancelled', 'ticket.change_status', 1, 0, 1, 0
    UNION ALL
    SELECT 'quotation_pending', 'authorization_pending', 'ticket.request_authorization', 1, 1, 1, 1
    UNION ALL
    SELECT 'under_review', 'cancelled', 'ticket.change_status', 1, 0, 1, 0
    UNION ALL
    SELECT 'under_review', 'authorization_pending', 'ticket.request_authorization', 1, 1, 1, 1
    UNION ALL
    SELECT 'under_review', 'quotation_pending', 'ticket.change_status', 0, 0, 1, 0
    UNION ALL
    SELECT 'under_review', 'in_progress', 'ticket.change_status', 1, 0, 1, 0
) AS rules
INNER JOIN `ticket_statuses` fs
    ON fs.`code` = rules.`from_code`
INNER JOIN `ticket_statuses` ts
    ON ts.`code` = rules.`to_code`
INNER JOIN `permissions` p
    ON p.`application_id` = @app_maintenance
   AND p.`code` = rules.`permission_code`
ON DUPLICATE KEY UPDATE
    `required_permission_id` = VALUES(`required_permission_id`),
    `requires_comment` = VALUES(`requires_comment`),
    `requires_quotation` = VALUES(`requires_quotation`),
    `requires_supervisor` = VALUES(`requires_supervisor`),
    `requires_director` = VALUES(`requires_director`),
    `is_active` = VALUES(`is_active`);

-- =========================================================
-- 6. Register migration
-- =========================================================

INSERT INTO `schema_migrations` (`version`, `name`)
VALUES ('03', 'maintenance_domain')
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);

-- =========================================================
-- 7. Validation
-- =========================================================

SELECT
    @app_maintenance AS `maintenance_app_id`;

SELECT
    COUNT(*) AS `maintenance_domain_tables_found`
FROM `information_schema`.`tables`
WHERE `table_schema` = DATABASE()
  AND `table_name` IN (
      'approval_statuses',
      'folio_sequences',
      'locations',
      'notifications',
      'system_settings',
      'tickets',
      'ticket_approval_requests',
      'ticket_assignment_history',
      'ticket_attachments',
      'ticket_comments',
      'ticket_priorities',
      'ticket_quotations',
      'ticket_statuses',
      'ticket_status_history',
      'ticket_status_transitions'
  );

SELECT
    (SELECT COUNT(*) FROM `approval_statuses`) AS `approval_statuses`,
    (SELECT COUNT(*) FROM `locations`) AS `locations`,
    (SELECT COUNT(*) FROM `ticket_priorities`) AS `ticket_priorities`,
    (SELECT COUNT(*) FROM `ticket_statuses`) AS `ticket_statuses`,
    (SELECT COUNT(*) FROM `ticket_status_transitions`) AS `ticket_status_transitions`,
    (SELECT COUNT(*) FROM `system_settings`) AS `system_settings`,
    (SELECT COUNT(*) FROM `folio_sequences`) AS `folio_sequences`;

SELECT
    COUNT(*) AS `invalid_transition_permissions`
FROM `ticket_status_transitions` tst
INNER JOIN `permissions` p
    ON p.`id` = tst.`required_permission_id`
WHERE p.`application_id` <> @app_maintenance;

SELECT
    (SELECT COUNT(*) FROM `tickets`) AS `tickets`,
    (SELECT COUNT(*) FROM `ticket_comments`) AS `ticket_comments`,
    (SELECT COUNT(*) FROM `ticket_attachments`) AS `ticket_attachments`,
    (SELECT COUNT(*) FROM `ticket_quotations`) AS `ticket_quotations`,
    (SELECT COUNT(*) FROM `ticket_approval_requests`) AS `ticket_approval_requests`,
    (SELECT COUNT(*) FROM `ticket_assignment_history`) AS `ticket_assignment_history`,
    (SELECT COUNT(*) FROM `ticket_status_history`) AS `ticket_status_history`,
    (SELECT COUNT(*) FROM `notifications`) AS `notifications`;

SELECT
    `version`,
    `name`,
    `applied_at`
FROM `schema_migrations`
WHERE `version` = '03';
