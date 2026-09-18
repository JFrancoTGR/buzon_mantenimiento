<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/bootstrap/app.php';
$pdo = $services['pdo'];

$ok = true;

$requiredFiles = [
    'bootstrap/app.php',
    'app/Services/QuotationService.php',
    'app/Services/TicketDetailService.php',
    'public/assets/js/modules/ticket-detail.js',
];

foreach ($requiredFiles as $relative) {
    $exists = is_file(ROOT_PATH . '/' . $relative);
    printf("%-68s %s\n", $relative, $exists ? 'OK' : 'FALTA');
    $ok = $ok && $exists;
}

echo PHP_EOL . "=== Transición de correcciones ===" . PHP_EOL;
$transition = $pdo->query(
    "SELECT tr.is_active, tr.requires_comment, tr.requires_quotation,
            tr.requires_supervisor, tr.requires_director,
            p.code AS permission_code,
            fs.code AS from_status, ts.code AS to_status
     FROM ticket_status_transitions tr
     INNER JOIN ticket_statuses fs ON fs.id = tr.from_status_id
     INNER JOIN ticket_statuses ts ON ts.id = tr.to_status_id
     INNER JOIN permissions p ON p.id = tr.required_permission_id
     WHERE fs.code = 'changes_requested'
       AND ts.code = 'quotation_pending'
     LIMIT 1"
)->fetch();

$transitionOk = is_array($transition)
    && (int) $transition['is_active'] === 1
    && (string) $transition['permission_code'] === 'ticket.change_status'
    && (int) $transition['requires_supervisor'] === 1
    && (int) $transition['requires_director'] === 0;
printf(
    "changes_requested → quotation_pending: %s%s\n",
    $transitionOk ? 'OK' : 'ERROR',
    is_array($transition) ? ' · permiso=' . $transition['permission_code'] : ''
);
$ok = $ok && $transitionOk;

echo PHP_EOL . "=== Código del parche ===" . PHP_EOL;
$quotationCode = @file_get_contents(ROOT_PATH . '/app/Services/QuotationService.php') ?: '';
$detailCode = @file_get_contents(ROOT_PATH . '/app/Services/TicketDetailService.php') ?: '';
$jsCode = @file_get_contents(ROOT_PATH . '/public/assets/js/modules/ticket-detail.js') ?: '';

$checks = [
    'Backend acepta changes_requested' => str_contains($quotationCode, "['quotation_pending', 'changes_requested']"),
    'Backend registra revisión' => str_contains($quotationCode, 'ticket.quotation.revision.submit'),
    'Capability permite changes_requested' => str_contains($detailCode, "['quotation_pending', 'changes_requested']"),
    'UI permite cargar en changes_requested' => str_contains($jsCode, "['quotation_pending', 'changes_requested'].includes"),
    'UI ofrece cargar nueva cotización' => str_contains($jsCode, 'Cargar nueva cotización'),
];
foreach ($checks as $label => $passed) {
    printf("%-46s %s\n", $label . ':', $passed ? 'OK' : 'ERROR');
    $ok = $ok && $passed;
}

echo PHP_EOL . "=== Ticket QA MNT-2026-000004 ===" . PHP_EOL;
$stmt = $pdo->prepare(
    "SELECT
        t.id, t.folio, s.code AS status_code, t.processing_route,
        t.supervisor_user_id, t.director_user_id, t.action_owner_user_id,
        t.row_version,
        q.id AS quotation_id, q.version_number, q.previous_quotation_id,
        q.status AS quotation_status, q.is_current
     FROM tickets t
     INNER JOIN ticket_statuses s ON s.id = t.current_status_id
     LEFT JOIN ticket_quotations q ON q.ticket_id = t.id AND q.is_current = 1
     WHERE t.folio = 'MNT-2026-000004'
     LIMIT 1"
);
$stmt->execute();
$ticket = $stmt->fetch();

if (!is_array($ticket)) {
    echo "MNT-2026-000004 no existe. El parche puede probarse con cualquier ticket en changes_requested.\n";
} else {
    printf(
        "%s | status=%s | route=%s | supervisor=%s | director=%s | owner=%s | row_version=%s\n",
        $ticket['folio'],
        $ticket['status_code'],
        $ticket['processing_route'] ?? 'NULL',
        $ticket['supervisor_user_id'] ?? 'NULL',
        $ticket['director_user_id'] ?? 'NULL',
        $ticket['action_owner_user_id'] ?? 'NULL',
        $ticket['row_version']
    );
    printf(
        "Cotización vigente: id=%s | versión=%s | previous=%s | status=%s | current=%s\n",
        $ticket['quotation_id'] ?? 'NULL',
        $ticket['version_number'] ?? 'NULL',
        $ticket['previous_quotation_id'] ?? 'NULL',
        $ticket['quotation_status'] ?? 'NULL',
        $ticket['is_current'] ?? 'NULL'
    );

    $approvalStmt = $pdo->prepare(
        "SELECT ar.id, ar.quotation_id, ar.supersedes_request_id,
                aps.code AS approval_status, ar.approver_user_id,
                ar.requested_at, ar.responded_at
         FROM ticket_approval_requests ar
         INNER JOIN approval_statuses aps ON aps.id = ar.status_id
         WHERE ar.ticket_id = :ticket_id
         ORDER BY ar.requested_at DESC, ar.id DESC
         LIMIT 1"
    );
    $approvalStmt->execute(['ticket_id' => (int) $ticket['id']]);
    $approval = $approvalStmt->fetch();
    if (is_array($approval)) {
        printf(
            "Última solicitud: id=%s | quote=%s | status=%s | supersedes=%s | director=%s\n",
            $approval['id'],
            $approval['quotation_id'],
            $approval['approval_status'],
            $approval['supersedes_request_id'] ?? 'NULL',
            $approval['approver_user_id']
        );
    }

    if ($ticket['status_code'] === 'changes_requested') {
        $ready = $ticket['processing_route'] === 'authorization_required'
            && (int) ($ticket['supervisor_user_id'] ?? 0) > 0
            && (int) ($ticket['action_owner_user_id'] ?? 0) === (int) ($ticket['supervisor_user_id'] ?? 0)
            && (int) ($ticket['is_current'] ?? 0) === 1;
        printf("Estado de prueba: %s\n", $ready ? 'LISTO PARA CARGAR COTIZACIÓN CORREGIDA' : 'REVISAR ASIGNACIÓN/ESTADO');
        $ok = $ok && $ready;
    } elseif ($ticket['status_code'] === 'quotation_pending') {
        echo "Estado de prueba: COTIZACIÓN CORREGIDA CARGADA · LISTO PARA SOLICITAR AUTORIZACIÓN\n";
    } elseif ($ticket['status_code'] === 'authorization_pending') {
        echo "Estado de prueba: NUEVA SOLICITUD ENVIADA A DIRECCIÓN\n";
    } else {
        echo "Estado de prueba: flujo avanzado a " . $ticket['status_code'] . "\n";
    }
}

echo PHP_EOL;
if ($ok) {
    echo "VALIDACIÓN CORRECTA\n";
    exit(0);
}

echo "VALIDACIÓN CON OBSERVACIONES\n";
exit(1);
