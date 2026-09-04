<?php

declare(strict_types=1);

use App\Config\Env;
use App\Core\Database;

require dirname(__DIR__) . '/bootstrap/app.php';

$pdo = Database::connection();

$requiredFiles = [
    'app/Services/TicketDetailService.php',
    'app/Services/AuthorizationDecisionService.php',
    'sql/13_administrator_authorization_boundary.sql',
];

$ok = true;
foreach ($requiredFiles as $relative) {
    $exists = is_file(dirname(__DIR__) . '/' . $relative);
    printf("%-70s %s\n", $relative, $exists ? 'OK' : 'FALTA');
    $ok = $ok && $exists;
}

$codes = ['ticket.authorize', 'ticket.reject', 'ticket.request_changes'];
$placeholders = implode(',', array_fill(0, count($codes), '?'));

$stmt = $pdo->prepare(
    "SELECT r.code AS role_code, p.code AS permission_code
     FROM role_permissions rp
     INNER JOIN roles r ON r.id = rp.role_id
     INNER JOIN permissions p ON p.id = rp.permission_id
     WHERE r.code IN ('administrator','director')
       AND p.code IN ($placeholders)
     ORDER BY r.code, p.code"
);
$stmt->execute($codes);
$rows = $stmt->fetchAll();

$adminPermissions = [];
$directorPermissions = [];
foreach ($rows as $row) {
    if ($row['role_code'] === 'administrator') {
        $adminPermissions[] = $row['permission_code'];
    }
    if ($row['role_code'] === 'director') {
        $directorPermissions[] = $row['permission_code'];
    }
}

printf("Administrador sin permisos de decisión: %s\n", $adminPermissions === [] ? 'OK' : 'ERROR');
$ok = $ok && $adminPermissions === [];

sort($directorPermissions);
$expected = $codes;
sort($expected);
$directorOk = $directorPermissions === $expected;
printf("Dirección conserva authorize/reject/request_changes: %s\n", $directorOk ? 'OK' : 'ERROR');
$ok = $ok && $directorOk;

$migration = $pdo->prepare("SELECT name FROM schema_migrations WHERE version = '13' LIMIT 1");
$migration->execute();
$name = $migration->fetchColumn();
$migrationOk = $name === 'administrator_authorization_boundary';
printf("Migración 13 registrada: %s\n", $migrationOk ? 'OK' : 'ERROR');
$ok = $ok && $migrationOk;

// Caso QA conocido, si existe: ticket MNT-2026-000001.
$stmt = $pdo->prepare(
    "SELECT t.id, t.folio, s.code AS status_code, t.director_user_id, t.action_owner_user_id, t.row_version
     FROM tickets t
     INNER JOIN ticket_statuses s ON s.id = t.current_status_id
     WHERE t.folio = 'MNT-2026-000001'
     LIMIT 1"
);
$stmt->execute();
$ticket = $stmt->fetch();
if (is_array($ticket)) {
    printf(
        "%s | status=%s | director=%s | owner=%s | row_version=%s\n",
        $ticket['folio'],
        $ticket['status_code'],
        $ticket['director_user_id'] ?? 'NULL',
        $ticket['action_owner_user_id'] ?? 'NULL',
        $ticket['row_version']
    );
}

fwrite(STDOUT, $ok ? "VALIDACIÓN CORRECTA\n" : "VALIDACIÓN CON ERRORES\n");
exit($ok ? 0 : 1);
