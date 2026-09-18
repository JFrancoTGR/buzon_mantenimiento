USE `u170017077_mantenimiento`;

-- Sustituir el correo antes de ejecutar.
SET @admin_email = 'admin@example.com';

SELECT
    u.id,
    u.first_name,
    u.last_name,
    u.email,
    u.status,
    u.must_change_password,
    GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ', ') AS roles,
    COUNT(DISTINCT p.id) AS effective_permissions,
    (SELECT COUNT(*) FROM permissions) AS total_permissions
FROM users u
LEFT JOIN user_roles ur ON ur.user_id = u.id
LEFT JOIN roles r ON r.id = ur.role_id
LEFT JOIN role_permissions rp ON rp.role_id = r.id
LEFT JOIN permissions p ON p.id = rp.permission_id
WHERE u.email = @admin_email
GROUP BY u.id, u.first_name, u.last_name, u.email, u.status, u.must_change_password;

SELECT
    r.code AS role_code,
    p.module,
    p.code AS permission_code
FROM roles r
INNER JOIN role_permissions rp ON rp.role_id = r.id
INNER JOIN permissions p ON p.id = rp.permission_id
WHERE r.code = 'administrator'
ORDER BY p.module, p.code;
