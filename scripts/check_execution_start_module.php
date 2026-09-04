<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/bootstrap/app.php';
$pdo = $services['pdo'];
$root = dirname(__DIR__);
$errors = [];

fwrite(STDOUT, "VALIDACIÓN DEL MÓDULO DE INICIO DE EJECUCIÓN\n");
fwrite(STDOUT, "===========================================\n");

$requiredFiles = [
    'app/Services/TicketDetailService.php',
    'public/api/tickets/start-execution.php',
    'public/assets/js/modules/ticket-detail.js',
    'public/ticket.html',
];
foreach ($requiredFiles as $relative) {
    $ok = is_file($root . '/' . $relative);
    fwrite(STDOUT, sprintf("%-62s %s\n", $relative, $ok ? 'OK' : 'FALTA'));
    if (!$ok) {
        $errors[] = "Falta {$relative}";
    }
}

$transition = $pdo->query(
    "SELECT COUNT(*)
     FROM ticket_status_transitions tr
     INNER JOIN ticket_statuses fs ON fs.id = tr.from_status_id
     INNER JOIN ticket_statuses ts ON ts.id = tr.to_status_id
     INNER JOIN permissions p ON p.id = tr.required_permission_id
     WHERE fs.code = 'authorized'
       AND ts.code = 'in_progress'
       AND p.code = 'ticket.change_status'
       AND tr.requires_comment = 0
       AND tr.requires_quotation = 1
       AND tr.requires_supervisor = 1
       AND tr.requires_director = 0
       AND tr.is_active = 1"
)->fetchColumn();
$transitionOk = (int) $transition === 1;
fwrite(STDOUT, 'authorized → in_progress: ' . ($transitionOk ? 'OK' : 'FALTA O NO COINCIDE') . PHP_EOL);
if (!$transitionOk) {
    $errors[] = 'La transición authorized → in_progress no coincide con las reglas esperadas.';
}

$ticketStatement = $pdo->prepare(
    "SELECT t.id, t.folio, s.code AS status_code, t.processing_route,
            t.supervisor_user_id, t.director_user_id, t.action_owner_user_id,
            t.authorized_at, t.started_at, t.row_version
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
        "MNT-2026-000001 | status=%s | route=%s | supervisor=%s | director=%s | owner=%s | row_version=%d\n",
        (string) $ticket['status_code'],
        $ticket['processing_route'] !== null ? (string) $ticket['processing_route'] : 'NULL',
        $ticket['supervisor_user_id'] !== null ? (string) $ticket['supervisor_user_id'] : 'NULL',
        $ticket['director_user_id'] !== null ? (string) $ticket['director_user_id'] : 'NULL',
        $ticket['action_owner_user_id'] !== null ? (string) $ticket['action_owner_user_id'] : 'NULL',
        (int) $ticket['row_version']
    ));
    fwrite(STDOUT, 'authorized_at=' . ($ticket['authorized_at'] ?? 'NULL') . ' | started_at=' . ($ticket['started_at'] ?? 'NULL') . PHP_EOL);

    if ((string) $ticket['processing_route'] !== 'authorization_required') {
        $errors[] = 'MNT-2026-000001 no está en la ruta authorization_required.';
    }
    if ($ticket['authorized_at'] === null) {
        $errors[] = 'MNT-2026-000001 no tiene authorized_at.';
    }
    if ((string) $ticket['status_code'] === 'authorized') {
        if ($ticket['started_at'] !== null) {
            $errors[] = 'Un ticket authorized todavía no debería tener started_at.';
        } else {
            fwrite(STDOUT, "Estado de prueba: LISTO PARA INICIAR EJECUCIÓN\n");
        }
    } elseif ((string) $ticket['status_code'] === 'in_progress') {
        if ($ticket['started_at'] === null) {
            $errors[] = 'El ticket ya está in_progress pero started_at es NULL.';
        } else {
            fwrite(STDOUT, "Estado de prueba: EJECUCIÓN YA INICIADA\n");
        }
    } else {
        $errors[] = 'MNT-2026-000001 no está en authorized ni in_progress.';
    }

    $approval = $pdo->prepare(
        "SELECT ar.id, ar.quotation_id, aps.code AS approval_status, ar.approved_amount, ar.responded_at,
                q.status AS quotation_status, q.is_current
         FROM ticket_approval_requests ar
         INNER JOIN approval_statuses aps ON aps.id = ar.status_id
         INNER JOIN ticket_quotations q ON q.id = ar.quotation_id
         WHERE ar.ticket_id = :ticket_id
           AND aps.code = 'approved'
           AND q.is_current = 1
           AND q.status = 'approved'
         ORDER BY ar.id DESC
         LIMIT 1"
    );
    $approval->execute(['ticket_id' => (int) $ticket['id']]);
    $approved = $approval->fetch();
    if (!is_array($approved)) {
        $errors[] = 'No existe una autorización aprobada vinculada con la cotización vigente.';
    } else {
        fwrite(STDOUT, sprintf(
            "Autorización aprobada: #%d | quotation=%d | amount=%s | responded_at=%s\n",
            (int) $approved['id'],
            (int) $approved['quotation_id'],
            (string) $approved['approved_amount'],
            (string) $approved['responded_at']
        ));
    }
}

if ($errors !== []) {
    fwrite(STDERR, "\nVALIDACIÓN CON OBSERVACIONES\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, "\nVALIDACIÓN CORRECTA\n");
