-- Plataforma de Gestión de Mantenimiento
-- Migración 14: consolidación final del MVP
--
-- Objetivos:
--   1. Fijar una matriz de permisos por perfil sin herencia operativa implícita.
--   2. Limitar cada cuenta a un único rol principal.
--   3. Convertir completed en estado transitorio y cerrar automáticamente.
--   4. Llevar a closed los tickets QA que quedaron estacionados en completed.
--
-- IMPORTANTE:
-- Después de esta migración, una ubicación solo podrá recibir tickets nuevos
-- si locations.default_supervisor_user_id apunta a un usuario ACTIVE cuyo
-- único rol sea supervisor. Administrator ya no funciona como fallback.

USE `u170017077_mantenimiento`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

START TRANSACTION;

-- =========================================================
-- 1. Un solo rol principal por usuario
-- =========================================================
-- En caso de que exista una cuenta con más de uno de los cuatro roles del MVP,
-- se conserva uno con esta prioridad de migración:
-- administrator > director > supervisor > reporter.
-- La prioridad solo resuelve datos históricos; NO expresa jerarquía de negocio.

DROP TEMPORARY TABLE IF EXISTS `tmp_mvp_primary_role`;

CREATE TEMPORARY TABLE `tmp_mvp_primary_role` (
    `user_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    `keep_role_id` SMALLINT UNSIGNED NOT NULL
) ENGINE=Memory;

INSERT INTO `tmp_mvp_primary_role` (`user_id`, `keep_role_id`)
SELECT
    ranked.`user_id`,
    CAST(
        SUBSTRING_INDEX(
            GROUP_CONCAT(
                ranked.`role_id`
                ORDER BY ranked.`role_priority` DESC, ranked.`role_id` DESC
                SEPARATOR ','
            ),
            ',',
            1
        ) AS UNSIGNED
    ) AS `keep_role_id`
FROM (
    SELECT
        ur.`user_id`,
        ur.`role_id`,
        CASE r.`code`
            WHEN 'administrator' THEN 40
            WHEN 'director' THEN 30
            WHEN 'supervisor' THEN 20
            WHEN 'reporter' THEN 10
            ELSE 0
        END AS `role_priority`
    FROM `user_roles` ur
    INNER JOIN `roles` r
        ON r.`id` = ur.`role_id`
    WHERE r.`code` IN ('reporter', 'supervisor', 'director', 'administrator')
) ranked
GROUP BY ranked.`user_id`;

DELETE ur
FROM `user_roles` ur
INNER JOIN `roles` r
    ON r.`id` = ur.`role_id`
INNER JOIN `tmp_mvp_primary_role` keep_role
    ON keep_role.`user_id` = ur.`user_id`
WHERE r.`code` IN ('reporter', 'supervisor', 'director', 'administrator')
  AND ur.`role_id` <> keep_role.`keep_role_id`;

DROP TEMPORARY TABLE IF EXISTS `tmp_mvp_primary_role`;

COMMIT;

-- El índice vuelve contractual la regla de un perfil por cuenta.
-- ALTER TABLE realiza commit implícito en MariaDB, por eso se ejecuta
-- fuera de la transacción de datos.
SET @single_role_index_exists := (
    SELECT COUNT(*)
    FROM `information_schema`.`statistics`
    WHERE `table_schema` = DATABASE()
      AND `table_name` = 'user_roles'
      AND `index_name` = 'uq_user_roles_single_role'
);

SET @sql_single_role_index := IF(
    @single_role_index_exists = 0,
    'ALTER TABLE `user_roles` ADD UNIQUE KEY `uq_user_roles_single_role` (`user_id`)',
    'SELECT ''El índice uq_user_roles_single_role ya existe'' AS message'
);

PREPARE stmt_single_role_index FROM @sql_single_role_index;
EXECUTE stmt_single_role_index;
DEALLOCATE PREPARE stmt_single_role_index;

START TRANSACTION;

-- =========================================================
-- 2. Matriz definitiva de permisos del MVP
-- =========================================================

UPDATE `roles`
SET `description` = CASE `code`
    WHEN 'reporter' THEN 'Origina y consulta sus propios reportes de mantenimiento.'
    WHEN 'supervisor' THEN 'Conduce la operación del mantenimiento, cotizaciones, autorizaciones y ejecución.'
    WHEN 'director' THEN 'Revisa y resuelve solicitudes de autorización que le sean asignadas.'
    WHEN 'administrator' THEN 'Gobierna usuarios, seguridad, catálogos, auditoría y visibilidad global del sistema.'
    ELSE `description`
END
WHERE `code` IN ('reporter', 'supervisor', 'director', 'administrator');

DELETE rp
FROM `role_permissions` rp
INNER JOIN `roles` r
    ON r.`id` = rp.`role_id`
WHERE r.`code` IN ('reporter', 'supervisor', 'director', 'administrator');

-- Reporter: originar y consultar sus propios reportes.
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `assigned_at`)
SELECT r.`id`, p.`id`, UTC_TIMESTAMP()
FROM `roles` r
INNER JOIN `permissions` p
    ON p.`code` IN (
        'ticket.create',
        'ticket.view.own',
        'ticket.comment',
        'ticket.upload.evidence'
    )
WHERE r.`code` = 'reporter';

-- Supervisor: originar reportes y conducir la operación del mantenimiento.
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `assigned_at`)
SELECT r.`id`, p.`id`, UTC_TIMESTAMP()
FROM `roles` r
INNER JOIN `permissions` p
    ON p.`code` IN (
        'ticket.create',
        'ticket.view.own',
        'ticket.view.assigned',
        'ticket.comment',
        'ticket.assign.director',
        'ticket.change_status',
        'ticket.upload.evidence',
        'ticket.upload.quotation',
        'ticket.request_authorization'
    )
WHERE r.`code` = 'supervisor';

-- Dirección: consultar lo asignado y resolver autorizaciones.
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `assigned_at`)
SELECT r.`id`, p.`id`, UTC_TIMESTAMP()
FROM `roles` r
INNER JOIN `permissions` p
    ON p.`code` IN (
        'ticket.view.assigned',
        'ticket.comment',
        'ticket.authorize',
        'ticket.reject',
        'ticket.request_changes'
    )
WHERE r.`code` = 'director';

-- Administrador: gobierno del sistema y visibilidad global, sin operación.
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `assigned_at`)
SELECT r.`id`, p.`id`, UTC_TIMESTAMP()
FROM `roles` r
INNER JOIN `permissions` p
    ON p.`code` IN (
        'ticket.view.all',
        'ticket.export',
        'user.manage',
        'role.manage',
        'catalog.manage',
        'audit.view'
    )
WHERE r.`code` = 'administrator';

-- =========================================================
-- 3. completed -> closed pasa a ser cierre automático
-- =========================================================

INSERT INTO `ticket_status_transitions` (
    `from_status_id`,
    `to_status_id`,
    `required_permission_id`,
    `requires_comment`,
    `requires_quotation`,
    `requires_supervisor`,
    `requires_director`,
    `is_active`,
    `created_at`
)
SELECT
    fs.`id`,
    ts.`id`,
    p.`id`,
    0,
    0,
    0,
    0,
    1,
    UTC_TIMESTAMP()
FROM `ticket_statuses` fs
INNER JOIN `ticket_statuses` ts
    ON ts.`code` = 'closed'
INNER JOIN `permissions` p
    ON p.`code` = 'ticket.change_status'
WHERE fs.`code` = 'completed'
ON DUPLICATE KEY UPDATE
    `required_permission_id` = VALUES(`required_permission_id`),
    `requires_comment` = 0,
    `requires_quotation` = 0,
    `requires_supervisor` = 0,
    `requires_director` = 0,
    `is_active` = 1;

-- =========================================================
-- 4. Backfill de tickets actualmente estacionados en completed
-- =========================================================
-- Conservamos completed_at como momento efectivo del cierre.
-- Se agrega un evento completed -> closed con source=migration.
-- row_version aumenta una sola vez por este cambio de expediente.

INSERT INTO `ticket_status_history` (
    `ticket_id`,
    `from_status_id`,
    `to_status_id`,
    `changed_by_user_id`,
    `comment`,
    `change_source`,
    `metadata_json`,
    `created_at`
)
SELECT
    t.`id`,
    completed_status.`id`,
    closed_status.`id`,
    COALESCE(t.`supervisor_user_id`, t.`reported_by_user_id`),
    'Cierre automático aplicado por la migración 14 al consolidar el flujo final del MVP.',
    'migration',
    JSON_OBJECT(
        'migration', '14',
        'automatic', TRUE,
        'completed_at', t.`completed_at`,
        'closed_at', COALESCE(t.`completed_at`, t.`updated_at`, UTC_TIMESTAMP())
    ),
    UTC_TIMESTAMP()
FROM `tickets` t
INNER JOIN `ticket_statuses` completed_status
    ON completed_status.`id` = t.`current_status_id`
   AND completed_status.`code` = 'completed'
INNER JOIN `ticket_statuses` closed_status
    ON closed_status.`code` = 'closed'
WHERE NOT EXISTS (
    SELECT 1
    FROM `ticket_status_history` h
    WHERE h.`ticket_id` = t.`id`
      AND h.`from_status_id` = completed_status.`id`
      AND h.`to_status_id` = closed_status.`id`
      AND h.`change_source` = 'migration'
);

UPDATE `tickets` t
INNER JOIN `ticket_statuses` current_status
    ON current_status.`id` = t.`current_status_id`
INNER JOIN `ticket_statuses` closed_status
    ON closed_status.`code` = 'closed'
SET
    t.`current_status_id` = closed_status.`id`,
    t.`closed_at` = COALESCE(t.`completed_at`, t.`updated_at`, UTC_TIMESTAMP()),
    t.`action_owner_user_id` = NULL,
    t.`row_version` = t.`row_version` + 1,
    t.`updated_at` = UTC_TIMESTAMP()
WHERE current_status.`code` = 'completed';

-- =========================================================
-- 5. Registrar migración
-- =========================================================

INSERT INTO `schema_migrations` (`version`, `name`)
VALUES ('14', 'mvp_role_matrix_auto_close')
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`);

COMMIT;

-- =========================================================
-- VALIDACIONES
-- =========================================================

-- Matriz de permisos resultante.
SELECT
    r.`code` AS `role_code`,
    GROUP_CONCAT(p.`code` ORDER BY p.`code` SEPARATOR ', ') AS `permissions`
FROM `roles` r
LEFT JOIN `role_permissions` rp
    ON rp.`role_id` = r.`id`
LEFT JOIN `permissions` p
    ON p.`id` = rp.`permission_id`
WHERE r.`code` IN ('reporter', 'supervisor', 'director', 'administrator')
GROUP BY r.`id`, r.`code`
ORDER BY FIELD(r.`code`, 'reporter', 'supervisor', 'director', 'administrator');

-- Debe ser 0.
SELECT COUNT(*) AS `users_with_multiple_roles`
FROM (
    SELECT `user_id`
    FROM `user_roles`
    GROUP BY `user_id`
    HAVING COUNT(*) > 1
) duplicated_roles;

-- completed debe quedar vacío en operación normal después del backfill.
SELECT
    s.`code` AS `status_code`,
    COUNT(t.`id`) AS `total`
FROM `ticket_statuses` s
LEFT JOIN `tickets` t
    ON t.`current_status_id` = s.`id`
WHERE s.`code` IN ('completed', 'closed')
GROUP BY s.`id`, s.`code`
ORDER BY s.`sort_order`;

-- Verifica qué ubicaciones ya tienen un supervisor operativo válido.
SELECT
    l.`code`,
    l.`name`,
    l.`default_supervisor_user_id`,
    CONCAT_WS(' ', u.`first_name`, u.`last_name`) AS `supervisor_name`,
    u.`status` AS `user_status`,
    r.`code` AS `role_code`,
    CASE
        WHEN u.`status` = 'active' AND r.`code` = 'supervisor' THEN 'OK'
        ELSE 'CONFIGURAR'
    END AS `supervisor_validation`
FROM `locations` l
LEFT JOIN `users` u
    ON u.`id` = l.`default_supervisor_user_id`
LEFT JOIN `user_roles` ur
    ON ur.`user_id` = u.`id`
LEFT JOIN `roles` r
    ON r.`id` = ur.`role_id`
WHERE l.`is_active` = 1
ORDER BY l.`sort_order`, l.`name`;

SELECT
    `version`,
    `name`,
    `applied_at`
FROM `schema_migrations`
WHERE `version` = '14';
