<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solamente puede ejecutarse desde CLI.\n");
    exit(1);
}

$services = require dirname(__DIR__) . '/bootstrap/app.php';
$pdo = $services['pdo'];
$email = isset($argv[1]) ? strtolower(trim((string) $argv[1])) : null;

if ($email !== null && $email !== '') {
    $userStatement = $pdo->prepare(
        'SELECT id, first_name, last_name, email
         FROM users
         WHERE email = :email AND status = "active"
         LIMIT 1'
    );
    $userStatement->execute(['email' => $email]);
} else {
    $userStatement = $pdo->query(
        'SELECT u.id, u.first_name, u.last_name, u.email
         FROM users u
         INNER JOIN user_roles ur ON ur.user_id = u.id
         INNER JOIN roles r ON r.id = ur.role_id
         WHERE u.status = "active" AND r.code = "administrator"
         ORDER BY u.id
         LIMIT 1'
    );
}

$user = $userStatement->fetch();
if (!is_array($user)) {
    fwrite(STDERR, "No se encontró un usuario activo para ejecutar la validación.\n");
    exit(1);
}

$permissionsStatement = $pdo->prepare(
    'SELECT DISTINCT p.code
     FROM user_roles ur
     INNER JOIN roles r ON r.id = ur.role_id AND r.is_active = 1
     INNER JOIN role_permissions rp ON rp.role_id = r.id
     INNER JOIN permissions p ON p.id = rp.permission_id
     WHERE ur.user_id = :user_id
     ORDER BY p.code'
);
$permissionsStatement->execute(['user_id' => (int) $user['id']]);
$user['permissions'] = array_map('strval', array_column($permissionsStatement->fetchAll(), 'code'));

$requiredTables = ['tickets', 'ticket_statuses', 'ticket_priorities', 'locations', 'ticket_status_history', 'notifications'];
$tableStatement = $pdo->prepare(
    'SELECT COUNT(*)
     FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = :table_name'
);

foreach ($requiredTables as $table) {
    $tableStatement->execute(['table_name' => $table]);
    if ((int) $tableStatement->fetchColumn() !== 1) {
        fwrite(STDERR, "Falta la tabla requerida: {$table}\n");
        exit(1);
    }
}

$summary = $services['dashboard']->summary($user);
$pending = $services['dashboard']->pendingActions($user);
$activity = $services['dashboard']->recentActivity($user);

echo "VALIDACIÓN DEL DASHBOARD\n";
echo "=======================\n";
echo 'Usuario: ' . trim((string) $user['first_name'] . ' ' . (string) $user['last_name']) . "\n";
echo 'Correo: ' . (string) $user['email'] . "\n";
echo 'Permisos efectivos: ' . count($user['permissions']) . "\n";
echo 'Contadores: ' . json_encode($summary['counters'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
echo 'Notificaciones sin leer: ' . $summary['unread_notifications'] . "\n";
echo 'Acciones pendientes obtenidas: ' . count($pending) . "\n";
echo 'Actividades recientes obtenidas: ' . count($activity) . "\n";
echo "VALIDACIÓN CORRECTA\n";
