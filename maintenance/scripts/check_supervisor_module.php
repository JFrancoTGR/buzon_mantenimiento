<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/bootstrap/app.php';
$pdo = $services['pdo'];
$root = dirname(__DIR__);
$errors = [];

fwrite(STDOUT, "VALIDACIÓN DEL MÓDULO DE SUPERVISOR\n");
fwrite(STDOUT, "=================================\n");

$requiredFiles = [
    'public/tickets.html',
    'public/assets/js/modules/tickets.js',
    'public/assets/css/pages/tickets.css',
    'public/api/supervisor/summary.php',
    'public/api/supervisor/tickets.php',
    'public/api/tickets/select-route.php',
    'app/Services/SupervisorService.php',
    'app/Services/TicketDetailService.php',
    'sql/11_ticket_processing_routes.sql',
];

foreach ($requiredFiles as $relative) {
    $ok = is_file($root . '/' . $relative);
    fwrite(STDOUT, sprintf("%-58s %s\n", $relative, $ok ? 'OK' : 'FALTA'));
    if (!$ok) {
        $errors[] = "Falta {$relative}";
    }
}

$column = $pdo->query(
    "SELECT COUNT(*)
     FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'tickets'
       AND column_name = 'processing_route'
       AND column_type = 'varchar(30)'
       AND is_nullable = 'YES'"
)->fetchColumn();
$columnOk = (int) $column === 1;
fwrite(STDOUT, 'tickets.processing_route: ' . ($columnOk ? 'OK' : 'FALTA O NO COINCIDE') . PHP_EOL);
if (!$columnOk) {
    $errors[] = 'La columna tickets.processing_route no coincide con la migración 11.';
}

$index = $pdo->query(
    "SELECT COUNT(DISTINCT index_name)
     FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'tickets'
       AND index_name = 'idx_tickets_processing_route'"
)->fetchColumn();
$indexOk = (int) $index === 1;
fwrite(STDOUT, 'Índice idx_tickets_processing_route: ' . ($indexOk ? 'OK' : 'FALTA') . PHP_EOL);
if (!$indexOk) {
    $errors[] = 'Falta idx_tickets_processing_route.';
}

$transitions = $pdo->query(
    "SELECT COUNT(*)
     FROM ticket_status_transitions tr
     INNER JOIN ticket_statuses fs ON fs.id = tr.from_status_id
     INNER JOIN ticket_statuses ts ON ts.id = tr.to_status_id
     WHERE tr.is_active = 1
       AND CONCAT(fs.code, '>', ts.code) IN (
          'under_review>in_progress',
          'under_review>quotation_pending',
          'quotation_pending>authorization_pending',
          'authorization_pending>authorized',
          'authorization_pending>rejected',
          'authorization_pending>changes_requested',
          'authorized>in_progress',
          'changes_requested>quotation_pending'
       )"
)->fetchColumn();
$transitionsOk = (int) $transitions === 8;
fwrite(STDOUT, 'Transiciones de rutas: ' . (int) $transitions . '/8 ' . ($transitionsOk ? 'OK' : 'REVISAR') . PHP_EOL);
if (!$transitionsOk) {
    $errors[] = 'No se encontraron las ocho transiciones activas de la migración 11.';
}

$qa = $pdo->query(
    "SELECT t.id, t.folio, s.code AS status_code, t.processing_route, t.row_version
     FROM tickets t
     INNER JOIN ticket_statuses s ON s.id = t.current_status_id
     WHERE t.folio IN ('MNT-2026-000001', 'MNT-2026-000002')
     ORDER BY t.folio"
)->fetchAll();

fwrite(STDOUT, 'Tickets QA encontrados: ' . count($qa) . '/2' . PHP_EOL);
foreach ($qa as $ticket) {
    fwrite(STDOUT, sprintf(
        "  %s | %s | route=%s | row_version=%d\n",
        (string) $ticket['folio'],
        (string) $ticket['status_code'],
        $ticket['processing_route'] !== null ? (string) $ticket['processing_route'] : 'NULL',
        (int) $ticket['row_version']
    ));
}
if (count($qa) !== 2) {
    $errors[] = 'No se encontraron los dos tickets QA esperados.';
}

$email = isset($argv[1]) ? strtolower(trim((string) $argv[1])) : '';
if ($email !== '') {
    $userStatement = $pdo->prepare(
        "SELECT id, first_name, last_name, email, must_change_password
         FROM users
         WHERE email = :email AND status = 'active'
         LIMIT 1"
    );
    $userStatement->execute(['email' => $email]);
    $row = $userStatement->fetch();

    if (!is_array($row)) {
        $errors[] = "No se encontró el usuario activo {$email}.";
    } else {
        $permissionStatement = $pdo->prepare(
            'SELECT DISTINCT p.code
             FROM user_roles ur
             INNER JOIN roles r ON r.id = ur.role_id AND r.is_active = 1
             INNER JOIN role_permissions rp ON rp.role_id = r.id
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = :user_id'
        );
        $permissionStatement->execute(['user_id' => (int) $row['id']]);
        $user = [
            'id' => (int) $row['id'],
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'full_name' => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']),
            'email' => (string) $row['email'],
            'must_change_password' => (bool) $row['must_change_password'],
            'permissions' => array_map('strval', array_column($permissionStatement->fetchAll(), 'code')),
        ];

        try {
            $summary = $services['supervisor']->summary($user);
            $tickets = $services['supervisor']->tickets($user, []);
            fwrite(STDOUT, 'Consulta de bandeja para usuario: OK' . PHP_EOL);
            fwrite(STDOUT, '  Alcance: ' . (string) ($summary['scope']['name'] ?? '—') . PHP_EOL);
            fwrite(STDOUT, '  Tickets visibles: ' . (int) ($tickets['total'] ?? 0) . PHP_EOL);
            fwrite(STDOUT, '  Requieren atención: ' . (int) ($summary['counters']['requires_attention'] ?? 0) . PHP_EOL);
        } catch (Throwable $exception) {
            $errors[] = 'No fue posible consultar la bandeja: ' . $exception->getMessage();
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "\nVALIDACIÓN CON OBSERVACIONES\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, "\nVALIDACIÓN CORRECTA\n");
