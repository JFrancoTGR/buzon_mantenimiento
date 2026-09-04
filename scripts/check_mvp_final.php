<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/bootstrap/app.php';
$pdo = $services['pdo'];

$ok = true;

$requiredFiles = [
    'sql/14_mvp_role_matrix_auto_close.sql',
    'app/Services/TicketService.php',
    'app/Services/TicketDetailService.php',
    'app/Services/QuotationService.php',
    'app/Services/AuthorizationRequestService.php',
    'app/Services/AuthorizationDecisionService.php',
    'app/Services/DashboardService.php',
    'app/Services/SupervisorService.php',
    'public/api/tickets/complete-work.php',
    'public/assets/js/modules/ticket-detail.js',
    'public/assets/js/modules/new-ticket.js',
    'public/assets/js/modules/tickets.js',
    'public/ticket.html',
    'public/tickets.html',
    'public/dashboard.html',
];

foreach ($requiredFiles as $relative) {
    $exists = is_file(ROOT_PATH . '/' . $relative);
    printf("%-72s %s\n", $relative, $exists ? 'OK' : 'FALTA');
    $ok = $ok && $exists;
}

echo PHP_EOL . "=== Migración ===" . PHP_EOL;
$migration = $pdo->prepare(
    "SELECT name, applied_at FROM schema_migrations WHERE version = '14' LIMIT 1"
);
$migration->execute();
$migrationRow = $migration->fetch();
$migrationOk = is_array($migrationRow) && $migrationRow['name'] === 'mvp_role_matrix_auto_close';
printf(
    "Migración 14 registrada: %s%s\n",
    $migrationOk ? 'OK' : 'ERROR',
    $migrationOk ? ' · ' . $migrationRow['applied_at'] : ''
);
$ok = $ok && $migrationOk;

echo PHP_EOL . "=== Rol único por cuenta ===" . PHP_EOL;
$index = $pdo->query(
    "SELECT COUNT(*)
     FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'user_roles'
       AND index_name = 'uq_user_roles_single_role'"
)->fetchColumn();
$indexOk = (int) $index > 0;
printf("Índice uq_user_roles_single_role: %s\n", $indexOk ? 'OK' : 'ERROR');
$ok = $ok && $indexOk;

$multipleRoles = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM (
       SELECT user_id
       FROM user_roles
       GROUP BY user_id
       HAVING COUNT(*) > 1
     ) x"
)->fetchColumn();
printf("Usuarios con más de un rol: %d\n", $multipleRoles);
$ok = $ok && $multipleRoles === 0;

echo PHP_EOL . "=== Matriz RBAC ===" . PHP_EOL;

$expected = [
    'reporter' => [
        'ticket.comment',
        'ticket.create',
        'ticket.upload.evidence',
        'ticket.view.own',
    ],
    'supervisor' => [
        'ticket.assign.director',
        'ticket.change_status',
        'ticket.comment',
        'ticket.create',
        'ticket.request_authorization',
        'ticket.upload.evidence',
        'ticket.upload.quotation',
        'ticket.view.assigned',
        'ticket.view.own',
    ],
    'director' => [
        'ticket.authorize',
        'ticket.comment',
        'ticket.reject',
        'ticket.request_changes',
        'ticket.view.assigned',
    ],
    'administrator' => [
        'audit.view',
        'catalog.manage',
        'role.manage',
        'ticket.export',
        'ticket.view.all',
        'user.manage',
    ],
];

$stmt = $pdo->query(
    "SELECT r.code AS role_code, p.code AS permission_code
     FROM role_permissions rp
     INNER JOIN roles r ON r.id = rp.role_id
     INNER JOIN permissions p ON p.id = rp.permission_id
     WHERE r.code IN ('reporter','supervisor','director','administrator')
     ORDER BY r.code, p.code"
);
$actual = [
    'reporter' => [],
    'supervisor' => [],
    'director' => [],
    'administrator' => [],
];
foreach ($stmt->fetchAll() as $row) {
    $actual[$row['role_code']][] = $row['permission_code'];
}

foreach ($expected as $role => $permissions) {
    sort($permissions);
    sort($actual[$role]);
    $roleOk = $permissions === $actual[$role];
    printf("%-15s %s\n", $role, $roleOk ? 'OK' : 'ERROR');
    if (!$roleOk) {
        echo "  Esperado: " . implode(', ', $permissions) . PHP_EOL;
        echo "  Actual  : " . implode(', ', $actual[$role]) . PHP_EOL;
    }
    $ok = $ok && $roleOk;
}

echo PHP_EOL . "=== Cierre automático ===" . PHP_EOL;
$transition = $pdo->query(
    "SELECT tr.is_active, tr.requires_comment, tr.requires_quotation,
            tr.requires_supervisor, tr.requires_director, p.code AS permission_code
     FROM ticket_status_transitions tr
     INNER JOIN ticket_statuses fs ON fs.id = tr.from_status_id
     INNER JOIN ticket_statuses ts ON ts.id = tr.to_status_id
     INNER JOIN permissions p ON p.id = tr.required_permission_id
     WHERE fs.code = 'completed' AND ts.code = 'closed'
     LIMIT 1"
)->fetch();

$transitionOk = is_array($transition)
    && (int) $transition['is_active'] === 1
    && (int) $transition['requires_comment'] === 0
    && (int) $transition['requires_quotation'] === 0
    && (int) $transition['requires_supervisor'] === 0
    && (int) $transition['requires_director'] === 0;
printf("completed → closed automático: %s\n", $transitionOk ? 'OK' : 'ERROR');
$ok = $ok && $transitionOk;

$statusCounts = $pdo->query(
    "SELECT s.code, COUNT(t.id) AS total
     FROM ticket_statuses s
     LEFT JOIN tickets t ON t.current_status_id = s.id
     WHERE s.code IN ('completed','closed')
     GROUP BY s.id, s.code"
)->fetchAll();

foreach ($statusCounts as $row) {
    printf("%-10s %d\n", $row['code'], (int) $row['total']);
}
$completedCount = 0;
foreach ($statusCounts as $row) {
    if ($row['code'] === 'completed') {
        $completedCount = (int) $row['total'];
    }
}
printf("Tickets estacionados en completed: %s\n", $completedCount === 0 ? '0 · OK' : $completedCount . ' · REVISAR');
$ok = $ok && $completedCount === 0;

echo PHP_EOL . "=== Tickets QA conocidos ===" . PHP_EOL;
$qa = $pdo->query(
    "SELECT t.folio, s.code AS status_code, t.processing_route,
            t.completed_at, t.closed_at, t.action_owner_user_id, t.row_version
     FROM tickets t
     INNER JOIN ticket_statuses s ON s.id = t.current_status_id
     WHERE t.folio IN ('MNT-2026-000001','MNT-2026-000002')
     ORDER BY t.folio"
)->fetchAll();

foreach ($qa as $ticket) {
    printf(
        "%s | status=%s | route=%s | completed=%s | closed=%s | owner=%s | row_version=%s\n",
        $ticket['folio'],
        $ticket['status_code'],
        $ticket['processing_route'] ?? 'NULL',
        $ticket['completed_at'] ?? 'NULL',
        $ticket['closed_at'] ?? 'NULL',
        $ticket['action_owner_user_id'] ?? 'NULL',
        $ticket['row_version']
    );
}

echo PHP_EOL . "=== Supervisores por ubicación ===" . PHP_EOL;
$locations = $pdo->query(
    "SELECT
        l.code,
        l.name,
        l.default_supervisor_user_id,
        CONCAT_WS(' ', u.first_name, u.last_name) AS supervisor_name,
        u.status AS user_status,
        r.code AS role_code
     FROM locations l
     LEFT JOIN users u ON u.id = l.default_supervisor_user_id
     LEFT JOIN user_roles ur ON ur.user_id = u.id
     LEFT JOIN roles r ON r.id = ur.role_id
     WHERE l.is_active = 1
     ORDER BY l.sort_order, l.name"
)->fetchAll();

$validLocations = 0;
foreach ($locations as $location) {
    $valid = $location['user_status'] === 'active' && $location['role_code'] === 'supervisor';
    if ($valid) {
        $validLocations++;
    }
    printf(
        "%-32s supervisor=%-4s %-28s role=%-14s %s\n",
        $location['code'],
        $location['default_supervisor_user_id'] ?? 'NULL',
        trim((string) ($location['supervisor_name'] ?? '')) ?: 'Sin asignar',
        $location['role_code'] ?? 'NULL',
        $valid ? 'OK' : 'CONFIGURAR'
    );
}

if ($validLocations !== count($locations)) {
    echo PHP_EOL;
    echo "ADVERTENCIA: antes de crear nuevos tickets configura un usuario ACTIVE con rol supervisor" . PHP_EOL;
    echo "en cada ubicación que vaya a participar en la prueba. Administrator ya no es un Supervisor válido." . PHP_EOL;
}

echo PHP_EOL;
echo $ok ? "VALIDACIÓN CORRECTA\n" : "VALIDACIÓN CON ERRORES\n";
exit($ok ? 0 : 1);
