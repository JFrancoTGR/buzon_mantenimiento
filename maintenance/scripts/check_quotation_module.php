<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/bootstrap/app.php';
$pdo = $services['pdo'];
$root = dirname(__DIR__);
$errors = [];

fwrite(STDOUT, "VALIDACIÓN DEL MÓDULO DE COTIZACIONES\n");
fwrite(STDOUT, "====================================\n");

$requiredFiles = [
    'app/Services/QuotationService.php',
    'public/api/tickets/quotations/create.php',
    'app/Services/TicketDetailService.php',
    'public/assets/js/modules/ticket-detail.js',
    'public/assets/css/pages/ticket-detail.css',
    'public/ticket.html',
    'sql/12_ticket_quotation_validity.sql',
];

foreach ($requiredFiles as $relative) {
    $ok = is_file($root . '/' . $relative);
    fwrite(STDOUT, sprintf("%-60s %s\n", $relative, $ok ? 'OK' : 'FALTA'));
    if (!$ok) {
        $errors[] = "Falta {$relative}";
    }
}

$column = $pdo->query(
    "SELECT COUNT(*)
     FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'ticket_quotations'
       AND column_name = 'valid_until'
       AND column_type = 'date'
       AND is_nullable = 'YES'"
)->fetchColumn();
$columnOk = (int) $column === 1;
fwrite(STDOUT, 'ticket_quotations.valid_until: ' . ($columnOk ? 'OK' : 'FALTA O NO COINCIDE') . PHP_EOL);
if (!$columnOk) {
    $errors[] = 'La columna ticket_quotations.valid_until no coincide con la migración 12.';
}

$index = $pdo->query(
    "SELECT COUNT(DISTINCT index_name)
     FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'ticket_quotations'
       AND index_name = 'idx_ticket_quotations_validity'"
)->fetchColumn();
$indexOk = (int) $index === 1;
fwrite(STDOUT, 'Índice idx_ticket_quotations_validity: ' . ($indexOk ? 'OK' : 'FALTA') . PHP_EOL);
if (!$indexOk) {
    $errors[] = 'Falta idx_ticket_quotations_validity.';
}

$migration = $pdo->query(
    "SELECT COUNT(*) FROM schema_migrations
     WHERE version = '12' AND name = 'ticket_quotation_validity'"
)->fetchColumn();
$migrationOk = (int) $migration === 1;
fwrite(STDOUT, 'Migración 12 registrada: ' . ($migrationOk ? 'OK' : 'FALTA') . PHP_EOL);
if (!$migrationOk) {
    $errors[] = 'La migración 12 no está registrada en schema_migrations.';
}

$permission = $pdo->query(
    "SELECT COUNT(*)
     FROM role_permissions rp
     INNER JOIN roles r ON r.id = rp.role_id
     INNER JOIN permissions p ON p.id = rp.permission_id
     WHERE r.code = 'supervisor'
       AND p.code = 'ticket.upload.quotation'"
)->fetchColumn();
$permissionOk = (int) $permission >= 1;
fwrite(STDOUT, 'Supervisor → ticket.upload.quotation: ' . ($permissionOk ? 'OK' : 'FALTA') . PHP_EOL);
if (!$permissionOk) {
    $errors[] = 'El rol supervisor no tiene ticket.upload.quotation.';
}

$ticketStatement = $pdo->prepare(
    "SELECT t.id, t.folio, s.code AS status_code, t.processing_route,
            t.supervisor_user_id, t.action_owner_user_id, t.row_version
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
        "MNT-2026-000001 | %s | route=%s | row_version=%d\n",
        (string) $ticket['status_code'],
        $ticket['processing_route'] !== null ? (string) $ticket['processing_route'] : 'NULL',
        (int) $ticket['row_version']
    ));

    $ready = (string) $ticket['status_code'] === 'quotation_pending'
        && (string) $ticket['processing_route'] === 'authorization_required'
        && (int) $ticket['supervisor_user_id'] > 0
        && (int) $ticket['action_owner_user_id'] === (int) $ticket['supervisor_user_id'];
    fwrite(STDOUT, 'Ticket listo para administrar cotizaciones: ' . ($ready ? 'OK' : 'REVISAR') . PHP_EOL);
    if (!$ready) {
        $errors[] = 'MNT-2026-000001 no está en la combinación esperada quotation_pending + authorization_required con control del supervisor.';
    }

    $quotationStatement = $pdo->prepare(
        "SELECT q.id, q.version_number, q.supplier_name, q.amount, q.currency,
                q.valid_until, q.status, q.is_current, a.original_name,
                a.storage_path, a.size_bytes
         FROM ticket_quotations q
         INNER JOIN ticket_attachments a ON a.id = q.attachment_id
         WHERE q.ticket_id = :ticket_id
         ORDER BY q.version_number DESC"
    );
    $quotationStatement->execute(['ticket_id' => (int) $ticket['id']]);
    $quotations = $quotationStatement->fetchAll();
    fwrite(STDOUT, 'Cotizaciones registradas: ' . count($quotations) . PHP_EOL);
    foreach ($quotations as $quotation) {
        $path = $root . '/storage/' . ltrim((string) $quotation['storage_path'], '/');
        fwrite(STDOUT, sprintf(
            "  v%d | %s | %s %s | status=%s | current=%d | archivo=%s | %s\n",
            (int) $quotation['version_number'],
            (string) $quotation['supplier_name'],
            (string) $quotation['amount'],
            (string) $quotation['currency'],
            (string) $quotation['status'],
            (int) $quotation['is_current'],
            (string) $quotation['original_name'],
            is_file($path) ? 'FILE OK' : 'FILE FALTA'
        ));
        if (!is_file($path)) {
            $errors[] = 'Falta físicamente el archivo de la cotización v' . (int) $quotation['version_number'] . '.';
        }
    }

    $currentCountStatement = $pdo->prepare(
        'SELECT COUNT(*) FROM ticket_quotations WHERE ticket_id = :ticket_id AND is_current = 1'
    );
    $currentCountStatement->execute(['ticket_id' => (int) $ticket['id']]);
    $currentCount = (int) $currentCountStatement->fetchColumn();
    $currentOk = $currentCount <= 1;
    fwrite(STDOUT, 'Cotizaciones marcadas como vigentes: ' . $currentCount . ' ' . ($currentOk ? 'OK' : 'REVISAR') . PHP_EOL);
    if (!$currentOk) {
        $errors[] = 'Existe más de una cotización vigente para el ticket.';
    }
}

if ($errors !== []) {
    fwrite(STDERR, "\nVALIDACIÓN CON OBSERVACIONES\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, "\nVALIDACIÓN CORRECTA\n");
