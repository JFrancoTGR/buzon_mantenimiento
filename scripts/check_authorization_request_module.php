<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/bootstrap/app.php';
$pdo = $services['pdo'];
$root = dirname(__DIR__);
$errors = [];

fwrite(STDOUT, "VALIDACIÓN DEL MÓDULO DE SOLICITUD DE AUTORIZACIÓN\n");
fwrite(STDOUT, "==================================================\n");

$requiredFiles = [
    'app/Services/AuthorizationRequestService.php',
    'public/api/tickets/request-authorization.php',
    'app/Services/TicketDetailService.php',
    'app/Services/MailerService.php',
    'public/assets/js/modules/ticket-detail.js',
    'public/assets/css/pages/ticket-detail.css',
    'public/ticket.html',
];

foreach ($requiredFiles as $relative) {
    $ok = is_file($root . '/' . $relative);
    fwrite(STDOUT, sprintf("%-62s %s\n", $relative, $ok ? 'OK' : 'FALTA'));
    if (!$ok) {
        $errors[] = "Falta {$relative}";
    }
}

$tableCount = $pdo->query(
    "SELECT COUNT(*)
     FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'ticket_approval_requests'"
)->fetchColumn();
$tableOk = (int) $tableCount === 1;
fwrite(STDOUT, 'Tabla ticket_approval_requests: ' . ($tableOk ? 'OK' : 'FALTA') . PHP_EOL);
if (!$tableOk) {
    $errors[] = 'Falta ticket_approval_requests.';
}

$pendingStatus = $pdo->query(
    "SELECT COUNT(*) FROM approval_statuses WHERE code = 'pending' AND is_terminal = 0"
)->fetchColumn();
$pendingOk = (int) $pendingStatus === 1;
fwrite(STDOUT, 'approval_statuses.pending: ' . ($pendingOk ? 'OK' : 'FALTA') . PHP_EOL);
if (!$pendingOk) {
    $errors[] = 'Falta el estado de autorización pending.';
}

$permission = $pdo->query(
    "SELECT COUNT(DISTINCT p.code)
     FROM role_permissions rp
     INNER JOIN roles r ON r.id = rp.role_id
     INNER JOIN permissions p ON p.id = rp.permission_id
     WHERE r.code = 'supervisor'
       AND p.code IN ('ticket.request_authorization', 'ticket.assign.director')"
)->fetchColumn();
$permissionOk = (int) $permission === 2;
fwrite(STDOUT, 'Supervisor → request_authorization + assign.director: ' . ($permissionOk ? 'OK' : 'FALTA') . PHP_EOL);
if (!$permissionOk) {
    $errors[] = 'El supervisor requiere ticket.request_authorization y ticket.assign.director.';
}

$transition = $pdo->query(
    "SELECT COUNT(*)
     FROM ticket_status_transitions tr
     INNER JOIN ticket_statuses fs ON fs.id = tr.from_status_id
     INNER JOIN ticket_statuses ts ON ts.id = tr.to_status_id
     INNER JOIN permissions p ON p.id = tr.required_permission_id
     WHERE fs.code = 'quotation_pending'
       AND ts.code = 'authorization_pending'
       AND p.code = 'ticket.request_authorization'
       AND tr.requires_comment = 1
       AND tr.requires_quotation = 1
       AND tr.requires_supervisor = 1
       AND tr.requires_director = 1
       AND tr.is_active = 1"
)->fetchColumn();
$transitionOk = (int) $transition === 1;
fwrite(STDOUT, 'quotation_pending → authorization_pending: ' . ($transitionOk ? 'OK' : 'FALTA O NO COINCIDE') . PHP_EOL);
if (!$transitionOk) {
    $errors[] = 'La transición a authorization_pending no coincide con las reglas esperadas.';
}

$directors = $pdo->query(
    "SELECT DISTINCT u.id, CONCAT_WS(' ', u.first_name, u.last_name) AS full_name, u.email
     FROM users u
     INNER JOIN user_roles ur ON ur.user_id = u.id
     INNER JOIN roles r ON r.id = ur.role_id
     WHERE u.status = 'active' AND r.code = 'director' AND r.is_active = 1
     ORDER BY u.id"
)->fetchAll();
fwrite(STDOUT, 'Responsables activos de Dirección: ' . count($directors) . PHP_EOL);
foreach ($directors as $director) {
    fwrite(STDOUT, sprintf("  #%d %s <%s>\n", (int) $director['id'], trim((string) $director['full_name']), (string) $director['email']));
}
if ($directors === []) {
    fwrite(STDOUT, "  AVISO: el módulo está instalado, pero no podrá enviarse una solicitud hasta asignar el rol director a un usuario activo.\n");
}

$ticketStatement = $pdo->prepare(
    "SELECT t.id, t.folio, s.code AS status_code, t.processing_route,
            t.supervisor_user_id, t.director_user_id, t.action_owner_user_id, t.row_version
     FROM tickets t
     INNER JOIN ticket_statuses s ON s.id = t.current_status_id
     WHERE t.folio = 'MNT-2026-000001'
     LIMIT 1"
);
$ticketStatement->execute();
$ticket = $ticketStatement->fetch();
if (!is_array($ticket)) {
    $errors[] = 'No se encontró MNT-2026-000001.';
} else {
    fwrite(STDOUT, sprintf(
        "MNT-2026-000001 | status=%s | route=%s | director=%s | owner=%s | row_version=%d\n",
        (string) $ticket['status_code'],
        $ticket['processing_route'] !== null ? (string) $ticket['processing_route'] : 'NULL',
        $ticket['director_user_id'] !== null ? (string) $ticket['director_user_id'] : 'NULL',
        $ticket['action_owner_user_id'] !== null ? (string) $ticket['action_owner_user_id'] : 'NULL',
        (int) $ticket['row_version']
    ));

    $currentQuotation = $pdo->prepare(
        "SELECT id, version_number, amount, currency, supplier_name
         FROM ticket_quotations
         WHERE ticket_id = :ticket_id AND is_current = 1 AND status = 'current'
         LIMIT 1"
    );
    $currentQuotation->execute(['ticket_id' => (int) $ticket['id']]);
    $quotation = $currentQuotation->fetch();
    if (is_array($quotation)) {
        fwrite(STDOUT, sprintf(
            "Cotización vigente: #%d v%d | %s %s | %s\n",
            (int) $quotation['id'],
            (int) $quotation['version_number'],
            (string) $quotation['amount'],
            (string) $quotation['currency'],
            (string) $quotation['supplier_name']
        ));
    } else {
        $errors[] = 'MNT-2026-000001 no tiene una cotización vigente.';
    }

    $requests = $pdo->prepare(
        "SELECT ar.id, aps.code AS status_code, ar.quotation_id, ar.approver_user_id,
                ar.requested_amount, ar.requested_at
         FROM ticket_approval_requests ar
         INNER JOIN approval_statuses aps ON aps.id = ar.status_id
         WHERE ar.ticket_id = :ticket_id
         ORDER BY ar.id DESC"
    );
    $requests->execute(['ticket_id' => (int) $ticket['id']]);
    $rows = $requests->fetchAll();
    fwrite(STDOUT, 'Solicitudes existentes: ' . count($rows) . PHP_EOL);
    foreach ($rows as $row) {
        fwrite(STDOUT, sprintf(
            "  #%d | %s | quotation=%d | approver=%d | amount=%s | %s\n",
            (int) $row['id'],
            (string) $row['status_code'],
            (int) $row['quotation_id'],
            (int) $row['approver_user_id'],
            (string) $row['requested_amount'],
            (string) $row['requested_at']
        ));
    }
}

if ($errors !== []) {
    fwrite(STDERR, "\nVALIDACIÓN CON OBSERVACIONES\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, "\nVALIDACIÓN CORRECTA\n");
