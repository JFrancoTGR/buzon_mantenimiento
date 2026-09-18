<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/bootstrap/app.php';
$pdo = $services['pdo'];

$checks = [
    'app/Services/TicketDetailService.php',
    'public/api/tickets/complete-work.php',
    'public/assets/js/modules/ticket-detail.js',
    'public/assets/css/pages/ticket-detail.css',
    'public/ticket.html',
];

$failed = false;
foreach ($checks as $relative) {
    $ok = is_file(ROOT_PATH . '/' . $relative);
    printf("%-68s %s\n", $relative, $ok ? 'OK' : 'FALTA');
    if (!$ok) $failed = true;
}

$transition = $pdo->query(
    "SELECT tr.id, p.code AS permission_code, tr.requires_comment, tr.requires_supervisor, tr.is_active
     FROM ticket_status_transitions tr
     INNER JOIN ticket_statuses fs ON fs.id = tr.from_status_id
     INNER JOIN ticket_statuses ts ON ts.id = tr.to_status_id
     INNER JOIN permissions p ON p.id = tr.required_permission_id
     WHERE fs.code = 'in_progress' AND ts.code = 'completed'
     LIMIT 1"
)->fetch();
$transitionOk = is_array($transition) && (int) $transition['is_active'] === 1;
echo 'in_progress → completed: ' . ($transitionOk ? 'OK' : 'FALTA') . PHP_EOL;
if (!$transitionOk) $failed = true;

$column = $pdo->query("SHOW COLUMNS FROM tickets LIKE 'completed_at'")->fetch();
echo 'tickets.completed_at: ' . (is_array($column) ? 'OK' : 'FALTA') . PHP_EOL;
if (!is_array($column)) $failed = true;

$summary = $pdo->query("SHOW COLUMNS FROM tickets LIKE 'resolution_summary'")->fetch();
echo 'tickets.resolution_summary: ' . (is_array($summary) ? 'OK' : 'FALTA') . PHP_EOL;
if (!is_array($summary)) $failed = true;

$attachmentColumn = $pdo->query("SHOW COLUMNS FROM ticket_attachments LIKE 'attachment_type'")->fetch();
$completionTypeOk = is_array($attachmentColumn) && str_contains((string) $attachmentColumn['Type'], 'completion_evidence');
echo 'ticket_attachments.completion_evidence: ' . ($completionTypeOk ? 'OK' : 'FALTA') . PHP_EOL;
if (!$completionTypeOk) $failed = true;

$ticketStmt = $pdo->query(
    "SELECT t.id, t.folio, s.code AS status_code, t.processing_route,
            t.supervisor_user_id, t.action_owner_user_id, t.started_at, t.completed_at, t.row_version
     FROM tickets t
     INNER JOIN ticket_statuses s ON s.id = t.current_status_id
     WHERE s.code = 'in_progress'
     ORDER BY t.updated_at DESC, t.id DESC
     LIMIT 10"
);
$tickets = $ticketStmt->fetchAll();
printf("Tickets actualmente En proceso: %d\n", count($tickets));
foreach ($tickets as $ticket) {
    printf(
        "%s | route=%s | supervisor=%s | owner=%s | started=%s | completed=%s | row_version=%d\n",
        $ticket['folio'],
        $ticket['processing_route'] ?? 'NULL',
        $ticket['supervisor_user_id'] ?? 'NULL',
        $ticket['action_owner_user_id'] ?? 'NULL',
        $ticket['started_at'] ?? 'NULL',
        $ticket['completed_at'] ?? 'NULL',
        (int) $ticket['row_version']
    );
}

if ($failed) {
    fwrite(STDERR, "VALIDACIÓN CON ERRORES\n");
    exit(1);
}

echo "VALIDACIÓN CORRECTA\n";
