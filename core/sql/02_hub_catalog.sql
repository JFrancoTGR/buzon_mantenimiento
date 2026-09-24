-- EU Tools Core
-- Migration 02: Hub application catalog
-- Database: u170017077_tools

USE `u170017077_tools`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- =========================================================
-- 1. Hub presentation metadata
-- =========================================================

ALTER TABLE `applications`
    ADD COLUMN `hub_status`
        ENUM('available','integration','coming_soon')
        NOT NULL DEFAULT 'available'
        AFTER `show_in_hub`,
    ADD COLUMN `hub_category`
        VARCHAR(80) NULL
        AFTER `hub_status`,
    ADD COLUMN `icon_key`
        VARCHAR(60) NULL
        AFTER `hub_category`;

-- =========================================================
-- 2. Existing applications
-- =========================================================

UPDATE `applications`
SET
    `hub_status` = 'available',
    `hub_category` = 'Sistema',
    `icon_key` = 'core'
WHERE `code` = 'core';

UPDATE `applications`
SET
    `hub_status` = 'integration',
    `hub_category` = 'Gestión operativa',
    `icon_key` = 'maintenance',
    `show_in_hub` = 1,
    `sort_order` = 10
WHERE `code` = 'maintenance';

-- =========================================================
-- 3. Visible roadmap
-- =========================================================

INSERT INTO `applications` (
    `code`,
    `name`,
    `description`,
    `base_path`,
    `show_in_hub`,
    `hub_status`,
    `hub_category`,
    `icon_key`,
    `sort_order`,
    `is_active`
) VALUES
    (
        'events',
        'Registro de eventos',
        'Registro y consulta de invitados para eventos corporativos y comerciales.',
        '/events',
        1,
        'integration',
        'Eventos',
        'events',
        20,
        0
    ),
    (
        'commercial_intelligence',
        'Inteligencia comercial',
        'Información consolidada para seguimiento comercial, desempeño y toma de decisiones.',
        '/commercial-intelligence',
        1,
        'coming_soon',
        'Comercial',
        'intelligence',
        30,
        0
    ),
    (
    'referrals',
    'Programa de referidos',
    'Gestión y seguimiento del programa de referidos de Estrategia Urbana.',
    '/referrals',
    1,
    'coming_soon',
    'Comercial',
    'referrals',
    40,
    0
)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `description` = VALUES(`description`),
    `base_path` = VALUES(`base_path`),
    `show_in_hub` = VALUES(`show_in_hub`),
    `hub_status` = VALUES(`hub_status`),
    `hub_category` = VALUES(`hub_category`),
    `icon_key` = VALUES(`icon_key`),
    `sort_order` = VALUES(`sort_order`),
    `is_active` = VALUES(`is_active`);

-- =========================================================
-- 4. Register migration
-- =========================================================

INSERT INTO `schema_migrations` (`version`, `name`)
VALUES ('02', 'hub_application_catalog')
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);

-- =========================================================
-- VALIDATION
-- =========================================================

SELECT
    `id`,
    `code`,
    `name`,
    `base_path`,
    `show_in_hub`,
    `hub_status`,
    `hub_category`,
    `icon_key`,
    `sort_order`,
    `is_active`
FROM `applications`
ORDER BY `sort_order`, `code`;

SELECT
    `version`,
    `name`,
    `applied_at`
FROM `schema_migrations`
ORDER BY `version`;