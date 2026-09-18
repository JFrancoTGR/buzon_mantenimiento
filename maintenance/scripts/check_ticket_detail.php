<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/bootstrap/app.php';
$pdo = $services['pdo'];

fwrite(STDOUT, "VALIDACIÓN DE VISTA DE TICKET\n");
fwrite(STDOUT, "=============================\n");

$requiredFiles = [
    'public/ticket.html',
    'public/assets/js/modules/ticket-detail.js',
    'public/assets/css/pages/ticket-detail.css',
    'public/api/tickets/detail.php',
    'public/api/tickets/comment.php',
    'public/api/tickets/transition.php',
    'public/api/tickets/attachment.php',
    'app/Services/TicketDetailService.php',
];

$errors = [];
foreach ($requiredFiles as $relative) {
    $exists = is_file(dirname(__DIR__) . '/' . $relative);
    fwrite(STDOUT, sprintf("%-54s %s\n", $relative, $exists ? 'OK' : 'FALTA'));
    if (!$exists) $errors[] = "Falta {$relative}";
}

$tables = ['tickets', 'ticket_comments', 'ticket_attachments', 'ticket_status_history', 'ticket_assignment_history', 'ticket_status_transitions', 'notifications', 'audit_log'];
foreach ($tables as $table) {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name'
    );
    $statement->execute(['table_name' => $table]);
    $ok = (int) $statement->fetchColumn() === 1;
    fwrite(STDOUT, sprintf("Tabla %-47s %s\n", $table, $ok ? 'OK' : 'FALTA'));
    if (!$ok) $errors[] = "Falta tabla {$table}";
}

$transition = $pdo->query(
    "SELECT COUNT(*)
     FROM ticket_status_transitions tr
     INNER JOIN ticket_statuses fs ON fs.id = tr.from_status_id
     INNER JOIN ticket_statuses ts ON ts.id = tr.to_status_id
     INNER JOIN permissions p ON p.id = tr.required_permission_id
     WHERE fs.code = 'new' AND ts.code = 'under_review'
       AND p.code = 'ticket.change_status' AND tr.is_active = 1"
)->fetchColumn();
$transitionOk = (int) $transition === 1;
fwrite(STDOUT, 'Transición new → under_review: ' . ($transitionOk ? 'OK' : 'FALTA') . PHP_EOL);
if (!$transitionOk) $errors[] = 'No está configurada la transición new → under_review.';

$latest = $pdo->query(
    'SELECT id, folio, supervisor_user_id, row_version FROM tickets ORDER BY id DESC LIMIT 1'
)->fetch();

if (is_array($latest)) {
    fwrite(STDOUT, "Ticket de prueba: {$latest['folio']} (ID {$latest['id']})\n");
    fwrite(STDOUT, 'Supervisor asignado: ' . ($latest['supervisor_user_id'] !== null ? 'OK' : 'PENDIENTE') . PHP_EOL);
    fwrite(STDOUT, 'Row version: ' . (int) $latest['row_version'] . PHP_EOL);

    $attachments = $pdo->prepare(
        'SELECT storage_path FROM ticket_attachments WHERE ticket_id = :ticket_id AND deleted_at IS NULL'
    );
    $attachments->execute(['ticket_id' => (int) $latest['id']]);
    $rows = $attachments->fetchAll();
    fwrite(STDOUT, 'Adjuntos registrados: ' . count($rows) . PHP_EOL);
    foreach ($rows as $row) {
        $path = dirname(__DIR__) . '/storage/' . ltrim((string) $row['storage_path'], '/');
        if (!is_file($path) || !is_readable($path)) {
            $errors[] = 'Adjunto no disponible: ' . $row['storage_path'];
        }
    }

    $email = $argv[1] ?? null;
    if (is_string($email) && $email !== '') {
        $userStatement = $pdo->prepare(
            'SELECT id, first_name, last_name, email, must_change_password FROM users WHERE email = :email AND status = \'active\' LIMIT 1'
        );
        $userStatement->execute(['email' => strtolower(trim($email))]);
        $userRow = $userStatement->fetch();
        if (!is_array($userRow)) {
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
            $permissionStatement->execute(['user_id' => (int) $userRow['id']]);
            $user = [
                'id' => (int) $userRow['id'],
                'first_name' => (string) $userRow['first_name'],
                'last_name' => (string) $userRow['last_name'],
                'full_name' => trim((string) $userRow['first_name'] . ' ' . (string) $userRow['last_name']),
                'email' => (string) $userRow['email'],
                'must_change_password' => (bool) $userRow['must_change_password'],
                'permissions' => array_map('strval', array_column($permissionStatement->fetchAll(), 'code')),
            ];
            try {
                $detail = $services['ticket_detail']->detail($user, (int) $latest['id']);
                fwrite(STDOUT, 'Consulta del expediente para usuario: OK' . PHP_EOL);
                fwrite(STDOUT, 'Comentarios: ' . count($detail['comments']) . PHP_EOL);
                fwrite(STDOUT, 'Historial de estados: ' . count($detail['status_history']) . PHP_EOL);
                fwrite(STDOUT, 'Historial de asignaciones: ' . count($detail['assignment_history']) . PHP_EOL);
            } catch (Throwable $exception) {
                $errors[] = 'No fue posible consultar el expediente: ' . $exception->getMessage();
            }
        }
    }
} else {
    fwrite(STDOUT, "No hay tickets; se omite la validación de expediente.\n");
}

if ($errors !== []) {
    fwrite(STDERR, "\nVALIDACIÓN CON OBSERVACIONES\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, "\nVALIDACIÓN CORRECTA\n");
