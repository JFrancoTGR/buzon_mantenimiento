<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/bootstrap/app.php';
$pdo = $services['pdo'];
$root = dirname(__DIR__);
$errors = [];

fwrite(STDOUT, "VALIDACIÓN DEL MÓDULO DE DECISIÓN DE DIRECCIÓN\n");
fwrite(STDOUT, "===========================================\n");

$requiredFiles = [
    'app/Services/AuthorizationDecisionService.php',
    'public/api/tickets/authorization-decision.php',
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

$statuses = $pdo->query(
    "SELECT COUNT(DISTINCT code)
     FROM approval_statuses
     WHERE code IN ('pending', 'approved', 'rejected', 'changes_requested')"
)->fetchColumn();
$statusOk = (int) $statuses === 4;
fwrite(STDOUT, 'Estados de aprobación requeridos: ' . ($statusOk ? 'OK' : 'FALTA') . PHP_EOL);
if (!$statusOk) {
    $errors[] = 'Faltan estados del catálogo approval_statuses.';
}

$directorPermissions = $pdo->query(
    "SELECT COUNT(DISTINCT p.code)
     FROM role_permissions rp
     INNER JOIN roles r ON r.id = rp.role_id
     INNER JOIN permissions p ON p.id = rp.permission_id
     WHERE r.code = 'director'
       AND p.code IN ('ticket.authorize', 'ticket.reject', 'ticket.request_changes', 'ticket.view.assigned')"
)->fetchColumn();
$permissionOk = (int) $directorPermissions === 4;
fwrite(STDOUT, 'Permisos de Dirección: ' . ($permissionOk ? 'OK' : 'FALTA') . PHP_EOL);
if (!$permissionOk) {
    $errors[] = 'El rol director no tiene todos los permisos necesarios.';
}

$transitions = $pdo->query(
    "SELECT fs.code AS from_code, ts.code AS to_code, p.code AS permission_code
     FROM ticket_status_transitions tr
     INNER JOIN ticket_statuses fs ON fs.id = tr.from_status_id
     INNER JOIN ticket_statuses ts ON ts.id = tr.to_status_id
     INNER JOIN permissions p ON p.id = tr.required_permission_id
     WHERE fs.code = 'authorization_pending'
       AND ts.code IN ('authorized', 'rejected', 'changes_requested')
       AND tr.is_active = 1
     ORDER BY ts.code"
)->fetchAll();
fwrite(STDOUT, 'Transiciones de Dirección activas: ' . count($transitions) . PHP_EOL);
foreach ($transitions as $transition) {
    fwrite(STDOUT, sprintf(
        "  %s → %s [%s]\n",
        (string) $transition['from_code'],
        (string) $transition['to_code'],
        (string) $transition['permission_code']
    ));
}
if (count($transitions) !== 3) {
    $errors[] = 'Deben existir tres transiciones activas desde authorization_pending.';
}

$ticketStmt = $pdo->prepare(
    "SELECT t.id, t.folio, s.code AS status_code, t.processing_route,
            t.supervisor_user_id, t.director_user_id, t.action_owner_user_id,
            t.row_version, t.started_at
     FROM tickets t
     INNER JOIN ticket_statuses s ON s.id = t.current_status_id
     WHERE t.folio = 'MNT-2026-000001'
     LIMIT 1"
);
$ticketStmt->execute();
$ticket = $ticketStmt->fetch();
if (!is_array($ticket)) {
    $errors[] = 'No se encontró MNT-2026-000001.';
} else {
    fwrite(STDOUT, sprintf(
        "MNT-2026-000001 | status=%s | route=%s | supervisor=%s | director=%s | owner=%s | row_version=%d\n",
        (string) $ticket['status_code'],
        (string) $ticket['processing_route'],
        $ticket['supervisor_user_id'] !== null ? (string) $ticket['supervisor_user_id'] : 'NULL',
        $ticket['director_user_id'] !== null ? (string) $ticket['director_user_id'] : 'NULL',
        $ticket['action_owner_user_id'] !== null ? (string) $ticket['action_owner_user_id'] : 'NULL',
        (int) $ticket['row_version']
    ));

    $requestStmt = $pdo->prepare(
        "SELECT ar.id, ar.quotation_id, aps.code AS approval_status,
                ar.requested_by_user_id, ar.approver_user_id,
                ar.requested_amount, ar.approved_amount,
                ar.decided_by_user_id, ar.responded_at
         FROM ticket_approval_requests ar
         INNER JOIN approval_statuses aps ON aps.id = ar.status_id
         WHERE ar.ticket_id = :ticket_id
         ORDER BY ar.id DESC
         LIMIT 1"
    );
    $requestStmt->execute(['ticket_id' => (int) $ticket['id']]);
    $request = $requestStmt->fetch();
    if (!is_array($request)) {
        $errors[] = 'El ticket no tiene solicitud de autorización.';
    } else {
        fwrite(STDOUT, sprintf(
            "Solicitud #%d | status=%s | quotation=%d | approver=%d | amount=%s | decided_by=%s\n",
            (int) $request['id'],
            (string) $request['approval_status'],
            (int) $request['quotation_id'],
            (int) $request['approver_user_id'],
            (string) $request['requested_amount'],
            $request['decided_by_user_id'] !== null ? (string) $request['decided_by_user_id'] : 'NULL'
        ));
    }
}

$activeDirectors = $pdo->query(
    "SELECT DISTINCT u.id, CONCAT_WS(' ', u.first_name, u.last_name) AS full_name, u.email
     FROM users u
     INNER JOIN user_roles ur ON ur.user_id = u.id
     INNER JOIN roles r ON r.id = ur.role_id
     WHERE u.status = 'active' AND r.code = 'director' AND r.is_active = 1
     ORDER BY u.id"
)->fetchAll();
fwrite(STDOUT, 'Directores activos: ' . count($activeDirectors) . PHP_EOL);
foreach ($activeDirectors as $director) {
    fwrite(STDOUT, sprintf("  #%d %s <%s>\n", (int) $director['id'], trim((string) $director['full_name']), (string) $director['email']));
}

if ($errors !== []) {
    fwrite(STDERR, "\nVALIDACIÓN CON OBSERVACIONES\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, "\nVALIDACIÓN CORRECTA\n");
