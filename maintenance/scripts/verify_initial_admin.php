<?php

declare(strict_types=1);


if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solamente puede ejecutarse desde CLI.\n");
}

$services = require dirname(__DIR__) . '/bootstrap/app.php';
/** @var PDO $pdo */
$pdo = $services['pdo'];

$email = strtolower(trim($argv[1] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php scripts/verify_initial_admin.php correo@dominio.com\n");
    exit(1);
}

$userStatement = $pdo->prepare(
    'SELECT id, first_name, last_name, email, status, must_change_password
     FROM users WHERE email = :email LIMIT 1'
);
$userStatement->execute(['email' => $email]);
$user = $userStatement->fetch();

if (!is_array($user)) {
    fwrite(STDERR, "No se encontró el usuario.\n");
    exit(1);
}

$rolesStatement = $pdo->prepare(
    'SELECT r.code
     FROM user_roles ur
     INNER JOIN roles r ON r.id = ur.role_id
     WHERE ur.user_id = :user_id
     ORDER BY r.code'
);
$rolesStatement->execute(['user_id' => $user['id']]);
$roles = array_column($rolesStatement->fetchAll(), 'code');

$permissionStatement = $pdo->prepare(
    'SELECT DISTINCT p.code
     FROM user_roles ur
     INNER JOIN roles r ON r.id = ur.role_id AND r.is_active = 1
     INNER JOIN role_permissions rp ON rp.role_id = r.id
     INNER JOIN permissions p ON p.id = rp.permission_id
     WHERE ur.user_id = :user_id
     ORDER BY p.code'
);
$permissionStatement->execute(['user_id' => $user['id']]);
$effectivePermissionCodes = array_map('strval', array_column($permissionStatement->fetchAll(), 'code'));
$expectedAdministratorPermissions = [
    'audit.view',
    'catalog.manage',
    'role.manage',
    'ticket.export',
    'ticket.view.all',
    'user.manage',
];
sort($effectivePermissionCodes);
sort($expectedAdministratorPermissions);

fwrite(STDOUT, "Usuario: {$user['first_name']} {$user['last_name']}\n");
fwrite(STDOUT, "Correo: {$user['email']}\n");
fwrite(STDOUT, "Estado: {$user['status']}\n");
fwrite(STDOUT, 'Cambio obligatorio: ' . ((int) $user['must_change_password'] === 1 ? 'sí' : 'no') . "\n");
fwrite(STDOUT, 'Roles: ' . implode(', ', $roles) . "\n");
fwrite(STDOUT, 'Permisos efectivos: ' . implode(', ', $effectivePermissionCodes) . "\n");

$isValid = $user['status'] === 'active'
    && $roles === ['administrator']
    && $effectivePermissionCodes === $expectedAdministratorPermissions;

fwrite(STDOUT, $isValid ? "VALIDACIÓN CORRECTA\n" : "VALIDACIÓN INCOMPLETA\n");
exit($isValid ? 0 : 2);
