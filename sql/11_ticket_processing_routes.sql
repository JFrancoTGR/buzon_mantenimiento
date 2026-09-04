-- Plataforma de Gestión de Mantenimiento
-- Migración 11: rutas de atención del ticket
-- Base de datos: u170017077_mantenimiento
-- Compatible con MariaDB / phpMyAdmin
--
-- Objetivos:
--   1. Agregar tickets.processing_route.
--   2. Permitir dos rutas desde "En revisión":
--        a) direct                  -> En proceso
--        b) authorization_required -> Cotización pendiente
--   3. Asegurar las transiciones del flujo de cotización y autorización.
--   4. Alinear permisos base de Supervisor y Dirección.
--   5. Registrar la migración en schema_migrations.
--
-- Valores permitidos por la aplicación:
--   - direct
--   - authorization_required
--
-- processing_route permanece NULL mientras el supervisor todavía no
-- determina cómo será atendido el reporte.
--
-- Consideraciones:
--   - No se utilizan restricciones CHECK.
--   - La selección y eventual bloqueo de la ruta se validarán en PHP.
--   - El cambio de ruta posterior requerirá una acción administrativa
--     explícita y deberá registrarse en audit_log.
--   - Los tickets existentes no se reasignan ni cambian de estado.

USE `u170017077_mantenimiento`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- =========================================================
-- 1. Agregar processing_route a tickets
-- =========================================================

SET @column_exists := (
    SELECT COUNT(*)
    FROM `information_schema`.`columns`
    WHERE `table_schema` = DATABASE()
      AND `table_name` = 'tickets'
      AND `column_name` = 'processing_route'
);

SET @sql_add_column := IF(
    @column_exists = 0,
    'ALTER TABLE `tickets`
       ADD COLUMN `processing_route` VARCHAR(30) NULL
       COMMENT ''direct|authorization_required''
       AFTER `current_status_id`',
    'SELECT ''La columna processing_route ya existe'' AS message'
);

PREPARE stmt_add_column FROM @sql_add_column;
EXECUTE stmt_add_column;
DEALLOCATE PREPARE stmt_add_column;

ALTER TABLE `tickets`
    MODIFY COLUMN `processing_route` VARCHAR(30) NULL
    COMMENT 'direct|authorization_required'
    AFTER `current_status_id`;

-- =========================================================
-- 2. Crear índice para filtros y métricas por ruta
-- =========================================================

SET @index_exists := (
    SELECT COUNT(*)
    FROM `information_schema`.`statistics`
    WHERE `table_schema` = DATABASE()
      AND `table_name` = 'tickets'
      AND `index_name` = 'idx_tickets_processing_route'
);

SET @sql_add_index := IF(
    @index_exists = 0,
    'ALTER TABLE `tickets`
       ADD INDEX `idx_tickets_processing_route`
       (`processing_route`, `current_status_id`, `updated_at`)',
    'SELECT ''El índice idx_tickets_processing_route ya existe'' AS message'
);

PREPARE stmt_add_index FROM @sql_add_index;
EXECUTE stmt_add_index;
DEALLOCATE PREPARE stmt_add_index;

-- =========================================================
-- 3. Validar catálogos, permisos y roles requeridos
-- =========================================================

SET @required_status_count := (
    SELECT COUNT(*)
    FROM `ticket_statuses`
    WHERE `code` IN (
        'under_review',
        'in_progress',
        'quotation_pending',
        'authorization_pending',
        'authorized',
        'rejected',
        'changes_requested'
    )
);

SET @required_permission_count := (
    SELECT COUNT(*)
    FROM `permissions`
    WHERE `code` IN (
        'ticket.change_status',
        'ticket.upload.quotation',
        'ticket.request_authorization',
        'ticket.authorize',
        'ticket.reject',
        'ticket.request_changes'
    )
);

SET @required_role_count := (
    SELECT COUNT(*)
    FROM `roles`
    WHERE `code` IN ('supervisor', 'director')
);

SET @requirements_ok := IF(
    @required_status_count = 7
    AND @required_permission_count = 6
    AND @required_role_count = 2,
    1,
    0
);

-- =========================================================
-- 4. Alinear permisos base por rol
-- =========================================================

INSERT INTO `role_permissions` (`role_id`, `permission_id`, `assigned_at`)
SELECT r.`id`, p.`id`, CURRENT_TIMESTAMP
FROM `roles` r
INNER JOIN `permissions` p
    ON p.`code` IN (
        'ticket.change_status',
        'ticket.upload.quotation',
        'ticket.request_authorization'
    )
WHERE r.`code` = 'supervisor'
ON DUPLICATE KEY UPDATE
    `assigned_at` = `role_permissions`.`assigned_at`;

INSERT INTO `role_permissions` (`role_id`, `permission_id`, `assigned_at`)
SELECT r.`id`, p.`id`, CURRENT_TIMESTAMP
FROM `roles` r
INNER JOIN `permissions` p
    ON p.`code` IN (
        'ticket.authorize',
        'ticket.reject',
        'ticket.request_changes'
    )
WHERE r.`code` = 'director'
ON DUPLICATE KEY UPDATE
    `assigned_at` = `role_permissions`.`assigned_at`;

-- =========================================================
-- 5. Asegurar transiciones de ambas rutas
-- =========================================================

-- En revisión -> En proceso (atención directa)
INSERT INTO `ticket_status_transitions` (
    `from_status_id`, `to_status_id`, `required_permission_id`,
    `requires_comment`, `requires_quotation`,
    `requires_supervisor`, `requires_director`,
    `is_active`, `created_at`
)
SELECT fs.`id`, ts.`id`, p.`id`,
       1, 0, 1, 0, 1, CURRENT_TIMESTAMP
FROM `ticket_statuses` fs
INNER JOIN `ticket_statuses` ts ON ts.`code` = 'in_progress'
INNER JOIN `permissions` p ON p.`code` = 'ticket.change_status'
WHERE fs.`code` = 'under_review'
ON DUPLICATE KEY UPDATE
    `required_permission_id` = VALUES(`required_permission_id`),
    `requires_comment` = VALUES(`requires_comment`),
    `requires_quotation` = VALUES(`requires_quotation`),
    `requires_supervisor` = VALUES(`requires_supervisor`),
    `requires_director` = VALUES(`requires_director`),
    `is_active` = VALUES(`is_active`);

-- En revisión -> Cotización pendiente
INSERT INTO `ticket_status_transitions` (
    `from_status_id`, `to_status_id`, `required_permission_id`,
    `requires_comment`, `requires_quotation`,
    `requires_supervisor`, `requires_director`,
    `is_active`, `created_at`
)
SELECT fs.`id`, ts.`id`, p.`id`,
       0, 0, 1, 0, 1, CURRENT_TIMESTAMP
FROM `ticket_statuses` fs
INNER JOIN `ticket_statuses` ts ON ts.`code` = 'quotation_pending'
INNER JOIN `permissions` p ON p.`code` = 'ticket.change_status'
WHERE fs.`code` = 'under_review'
ON DUPLICATE KEY UPDATE
    `required_permission_id` = VALUES(`required_permission_id`),
    `requires_comment` = VALUES(`requires_comment`),
    `requires_quotation` = VALUES(`requires_quotation`),
    `requires_supervisor` = VALUES(`requires_supervisor`),
    `requires_director` = VALUES(`requires_director`),
    `is_active` = VALUES(`is_active`);

-- Cotización pendiente -> Pendiente de autorización
INSERT INTO `ticket_status_transitions` (
    `from_status_id`, `to_status_id`, `required_permission_id`,
    `requires_comment`, `requires_quotation`,
    `requires_supervisor`, `requires_director`,
    `is_active`, `created_at`
)
SELECT fs.`id`, ts.`id`, p.`id`,
       1, 1, 1, 1, 1, CURRENT_TIMESTAMP
FROM `ticket_statuses` fs
INNER JOIN `ticket_statuses` ts ON ts.`code` = 'authorization_pending'
INNER JOIN `permissions` p ON p.`code` = 'ticket.request_authorization'
WHERE fs.`code` = 'quotation_pending'
ON DUPLICATE KEY UPDATE
    `required_permission_id` = VALUES(`required_permission_id`),
    `requires_comment` = VALUES(`requires_comment`),
    `requires_quotation` = VALUES(`requires_quotation`),
    `requires_supervisor` = VALUES(`requires_supervisor`),
    `requires_director` = VALUES(`requires_director`),
    `is_active` = VALUES(`is_active`);

-- Pendiente de autorización -> Autorizado
INSERT INTO `ticket_status_transitions` (
    `from_status_id`, `to_status_id`, `required_permission_id`,
    `requires_comment`, `requires_quotation`,
    `requires_supervisor`, `requires_director`,
    `is_active`, `created_at`
)
SELECT fs.`id`, ts.`id`, p.`id`,
       1, 1, 0, 1, 1, CURRENT_TIMESTAMP
FROM `ticket_statuses` fs
INNER JOIN `ticket_statuses` ts ON ts.`code` = 'authorized'
INNER JOIN `permissions` p ON p.`code` = 'ticket.authorize'
WHERE fs.`code` = 'authorization_pending'
ON DUPLICATE KEY UPDATE
    `required_permission_id` = VALUES(`required_permission_id`),
    `requires_comment` = VALUES(`requires_comment`),
    `requires_quotation` = VALUES(`requires_quotation`),
    `requires_supervisor` = VALUES(`requires_supervisor`),
    `requires_director` = VALUES(`requires_director`),
    `is_active` = VALUES(`is_active`);

-- Pendiente de autorización -> Rechazado
INSERT INTO `ticket_status_transitions` (
    `from_status_id`, `to_status_id`, `required_permission_id`,
    `requires_comment`, `requires_quotation`,
    `requires_supervisor`, `requires_director`,
    `is_active`, `created_at`
)
SELECT fs.`id`, ts.`id`, p.`id`,
       1, 1, 0, 1, 1, CURRENT_TIMESTAMP
FROM `ticket_statuses` fs
INNER JOIN `ticket_statuses` ts ON ts.`code` = 'rejected'
INNER JOIN `permissions` p ON p.`code` = 'ticket.reject'
WHERE fs.`code` = 'authorization_pending'
ON DUPLICATE KEY UPDATE
    `required_permission_id` = VALUES(`required_permission_id`),
    `requires_comment` = VALUES(`requires_comment`),
    `requires_quotation` = VALUES(`requires_quotation`),
    `requires_supervisor` = VALUES(`requires_supervisor`),
    `requires_director` = VALUES(`requires_director`),
    `is_active` = VALUES(`is_active`);

-- Pendiente de autorización -> Correcciones solicitadas
INSERT INTO `ticket_status_transitions` (
    `from_status_id`, `to_status_id`, `required_permission_id`,
    `requires_comment`, `requires_quotation`,
    `requires_supervisor`, `requires_director`,
    `is_active`, `created_at`
)
SELECT fs.`id`, ts.`id`, p.`id`,
       1, 1, 0, 1, 1, CURRENT_TIMESTAMP
FROM `ticket_statuses` fs
INNER JOIN `ticket_statuses` ts ON ts.`code` = 'changes_requested'
INNER JOIN `permissions` p ON p.`code` = 'ticket.request_changes'
WHERE fs.`code` = 'authorization_pending'
ON DUPLICATE KEY UPDATE
    `required_permission_id` = VALUES(`required_permission_id`),
    `requires_comment` = VALUES(`requires_comment`),
    `requires_quotation` = VALUES(`requires_quotation`),
    `requires_supervisor` = VALUES(`requires_supervisor`),
    `requires_director` = VALUES(`requires_director`),
    `is_active` = VALUES(`is_active`);

-- Autorizado -> En proceso
INSERT INTO `ticket_status_transitions` (
    `from_status_id`, `to_status_id`, `required_permission_id`,
    `requires_comment`, `requires_quotation`,
    `requires_supervisor`, `requires_director`,
    `is_active`, `created_at`
)
SELECT fs.`id`, ts.`id`, p.`id`,
       0, 1, 1, 0, 1, CURRENT_TIMESTAMP
FROM `ticket_statuses` fs
INNER JOIN `ticket_statuses` ts ON ts.`code` = 'in_progress'
INNER JOIN `permissions` p ON p.`code` = 'ticket.change_status'
WHERE fs.`code` = 'authorized'
ON DUPLICATE KEY UPDATE
    `required_permission_id` = VALUES(`required_permission_id`),
    `requires_comment` = VALUES(`requires_comment`),
    `requires_quotation` = VALUES(`requires_quotation`),
    `requires_supervisor` = VALUES(`requires_supervisor`),
    `requires_director` = VALUES(`requires_director`),
    `is_active` = VALUES(`is_active`);

-- Correcciones solicitadas -> Cotización pendiente
INSERT INTO `ticket_status_transitions` (
    `from_status_id`, `to_status_id`, `required_permission_id`,
    `requires_comment`, `requires_quotation`,
    `requires_supervisor`, `requires_director`,
    `is_active`, `created_at`
)
SELECT fs.`id`, ts.`id`, p.`id`,
       0, 0, 1, 0, 1, CURRENT_TIMESTAMP
FROM `ticket_statuses` fs
INNER JOIN `ticket_statuses` ts ON ts.`code` = 'quotation_pending'
INNER JOIN `permissions` p ON p.`code` = 'ticket.change_status'
WHERE fs.`code` = 'changes_requested'
ON DUPLICATE KEY UPDATE
    `required_permission_id` = VALUES(`required_permission_id`),
    `requires_comment` = VALUES(`requires_comment`),
    `requires_quotation` = VALUES(`requires_quotation`),
    `requires_supervisor` = VALUES(`requires_supervisor`),
    `requires_director` = VALUES(`requires_director`),
    `is_active` = VALUES(`is_active`);

-- =========================================================
-- 6. Backfill seguro
-- =========================================================

UPDATE `tickets` t
INNER JOIN `ticket_statuses` s
    ON s.`id` = t.`current_status_id`
SET t.`processing_route` = 'authorization_required'
WHERE t.`processing_route` IS NULL
  AND (
      s.`code` IN (
          'quotation_pending',
          'authorization_pending',
          'changes_requested',
          'authorized',
          'rejected'
      )
      OR EXISTS (
          SELECT 1
          FROM `ticket_approval_requests` ar
          WHERE ar.`ticket_id` = t.`id`
      )
  );

-- =========================================================
-- 7. Registrar migración
-- =========================================================

INSERT INTO `schema_migrations` (`version`, `name`)
SELECT '11', 'ticket_processing_routes'
WHERE @requirements_ok = 1
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);

-- =========================================================
-- 8. Validaciones
-- =========================================================

SELECT
    CASE
        WHEN @requirements_ok = 1 THEN 'OK'
        ELSE 'ERROR: faltan estados, permisos o roles requeridos'
    END AS `requirements_validation`,
    @required_status_count AS `required_statuses_found`,
    @required_permission_count AS `required_permissions_found`,
    @required_role_count AS `required_roles_found`;

SELECT
    `column_name`,
    `column_type`,
    `is_nullable`,
    `column_comment`
FROM `information_schema`.`columns`
WHERE `table_schema` = DATABASE()
  AND `table_name` = 'tickets'
  AND `column_name` = 'processing_route';

SELECT
    `index_name`,
    `index_type`,
    GROUP_CONCAT(`column_name` ORDER BY `seq_in_index`) AS `indexed_columns`
FROM `information_schema`.`statistics`
WHERE `table_schema` = DATABASE()
  AND `table_name` = 'tickets'
  AND `index_name` = 'idx_tickets_processing_route'
GROUP BY `index_name`, `index_type`;

SELECT
    fs.`code` AS `from_status`,
    ts.`code` AS `to_status`,
    p.`code` AS `required_permission`,
    tr.`requires_comment`,
    tr.`requires_quotation`,
    tr.`requires_supervisor`,
    tr.`requires_director`,
    tr.`is_active`
FROM `ticket_status_transitions` tr
INNER JOIN `ticket_statuses` fs
    ON fs.`id` = tr.`from_status_id`
INNER JOIN `ticket_statuses` ts
    ON ts.`id` = tr.`to_status_id`
INNER JOIN `permissions` p
    ON p.`id` = tr.`required_permission_id`
WHERE (
       fs.`code` = 'under_review'
   AND ts.`code` IN ('in_progress', 'quotation_pending')
)
OR (
       fs.`code` = 'quotation_pending'
   AND ts.`code` = 'authorization_pending'
)
OR (
       fs.`code` = 'authorization_pending'
   AND ts.`code` IN ('authorized', 'rejected', 'changes_requested')
)
OR (
       fs.`code` = 'authorized'
   AND ts.`code` = 'in_progress'
)
OR (
       fs.`code` = 'changes_requested'
   AND ts.`code` = 'quotation_pending'
)
ORDER BY fs.`sort_order`, ts.`sort_order`;

SELECT
    r.`code` AS `role_code`,
    p.`code` AS `permission_code`
FROM `role_permissions` rp
INNER JOIN `roles` r
    ON r.`id` = rp.`role_id`
INNER JOIN `permissions` p
    ON p.`id` = rp.`permission_id`
WHERE (
       r.`code` = 'supervisor'
   AND p.`code` IN (
       'ticket.change_status',
       'ticket.upload.quotation',
       'ticket.request_authorization'
   )
)
OR (
       r.`code` = 'director'
   AND p.`code` IN (
       'ticket.authorize',
       'ticket.reject',
       'ticket.request_changes'
   )
)
ORDER BY r.`code`, p.`code`;

SELECT
    COALESCE(`processing_route`, 'not_selected') AS `processing_route`,
    COUNT(*) AS `total_tickets`
FROM `tickets`
GROUP BY `processing_route`
ORDER BY `processing_route`;

SELECT
    `version`,
    `name`,
    `applied_at`
FROM `schema_migrations`
WHERE `version` = '11';

SHOW CREATE TABLE `tickets`;
