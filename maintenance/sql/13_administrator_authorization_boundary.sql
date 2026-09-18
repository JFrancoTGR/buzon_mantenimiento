-- Plataforma de Gestión de Mantenimiento
-- Migración 13: separación entre Administración del sistema y autorización operativa
--
-- Objetivo:
--   El rol administrator conserva visibilidad/administración del sistema,
--   pero deja de heredar las decisiones de negocio propias de Dirección.
--
-- Esta migración NO modifica todavía permisos como ticket.change_status,
-- ticket.upload.quotation, ticket.request_authorization o comentarios.
-- Esa política se definirá por separado para no mezclar decisiones pendientes.

USE `u170017077_mantenimiento`;

START TRANSACTION;

DELETE rp
FROM `role_permissions` rp
INNER JOIN `roles` r
    ON r.id = rp.role_id
INNER JOIN `permissions` p
    ON p.id = rp.permission_id
WHERE r.code = 'administrator'
  AND p.code IN (
      'ticket.authorize',
      'ticket.reject',
      'ticket.request_changes'
  );

INSERT INTO `schema_migrations` (`version`, `name`)
VALUES ('13', 'administrator_authorization_boundary')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

COMMIT;

-- =========================================================
-- Validación
-- =========================================================

SELECT
    r.code AS role_code,
    p.code AS permission_code
FROM `role_permissions` rp
INNER JOIN `roles` r ON r.id = rp.role_id
INNER JOIN `permissions` p ON p.id = rp.permission_id
WHERE r.code IN ('administrator', 'director')
  AND p.code IN (
      'ticket.authorize',
      'ticket.reject',
      'ticket.request_changes'
  )
ORDER BY r.code, p.code;

-- Resultado esperado:
-- Solo deben aparecer 3 filas, todas para role_code = director.

SELECT
    `version`,
    `name`,
    `applied_at`
FROM `schema_migrations`
WHERE `version` = '13';
