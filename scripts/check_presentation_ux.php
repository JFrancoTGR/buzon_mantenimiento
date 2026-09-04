<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [];

$requiredFiles = [
    'app/Services/TicketPresentationPolicy.php',
    'app/Services/NotificationService.php',
    'app/Services/DashboardService.php',
    'app/Services/SupervisorService.php',
    'app/Services/TicketDetailService.php',
    'app/Services/QuotationService.php',
    'app/Services/AuthorizationRequestService.php',
    'app/Services/AuthorizationDecisionService.php',
    'public/api/notifications/read.php',
    'public/api/notifications/read-all.php',
    'public/assets/js/components/notificationsMenu.js',
    'public/assets/js/modules/ticket-detail.js',
    'public/assets/js/modules/tickets.js',
    'public/assets/js/modules/dashboard.js',
];

foreach ($requiredFiles as $file) {
    $ok = is_file($root . '/' . $file);
    printf("%-76s %s\n", $file, $ok ? 'OK' : 'FALTA');
    $checks[] = $ok;
}

function contains(string $path, string $needle): bool
{
    $content = @file_get_contents($path);
    return is_string($content) && str_contains($content, $needle);
}

function notContains(string $path, string $needle): bool
{
    return !contains($path, $needle);
}

function checkLine(string $label, bool $ok): void
{
    global $checks;
    printf("%-54s %s\n", $label . ':', $ok ? 'OK' : 'REVISAR');
    $checks[] = $ok;
}

echo "\n=== Presentación Reporter ===\n";
checkLine('Política pública de estados', contains($root . '/app/Services/TicketPresentationPolicy.php', "'authorization_pending', 'changes_requested', 'authorized'"));
checkLine('Detalle identifica vista Reporter', contains($root . '/app/Services/TicketDetailService.php', "'is_reporter_view' => \$isReporterView"));
checkLine('Historial público filtra transiciones internas', contains($root . '/app/Services/TicketDetailService.php', 'publicStatusHistory'));
checkLine('Historial público oculta notas operativas', contains($root . '/app/Services/TicketDetailService.php', "\$item['comment'] = null"));
checkLine('Aviso público usa En gestión', contains($root . '/app/Services/TicketDetailService.php', "ahora está En gestión"));
checkLine('Asignaciones internas ocultas al Reporter', contains($root . '/app/Services/TicketDetailService.php', "'assignment_history' => \$isReporterView ? []"));
checkLine('Dirección oculta en vista Reporter', contains($root . '/app/Services/TicketDetailService.php', "\$ticket['director'] = null"));
checkLine('Bandeja usa estados públicos', contains($root . '/app/Services/SupervisorService.php', 'internalCodesForPublicStatus'));

echo "\n=== Privacidad Supervisor ↔ Dirección ===\n";
checkLine('Solicitud ya no notifica al Reporter', notContains($root . '/app/Services/AuthorizationRequestService.php', 'ticket.authorization.reporter'));
checkLine('Corrección de cotización ya no notifica al Reporter', notContains($root . '/app/Services/QuotationService.php', 'ticket.quotation.revised'));
$decisionContent = (string) @file_get_contents($root . '/app/Services/AuthorizationDecisionService.php');
$decisionBlock = '';
if (preg_match('/private function decisionRecipients\(.*?\n    }\n/s', $decisionContent, $match)) {
    $decisionBlock = $match[0];
}
checkLine('Decisiones de Dirección excluyen al Reporter', $decisionBlock !== '' && !str_contains($decisionBlock, 'reported_by_user_id'));
checkLine('Ruta con autorización no genera aviso público', contains($root . '/app/Services/TicketDetailService.php', "\$notificationRecipients = \$processingRoute === 'direct'"));
checkLine('Correos de gestión no exponen notas internas', contains($root . '/app/Services/TicketDetailService.php', "'En gestión',\n                    null") && contains($root . '/app/Services/TicketDetailService.php', "'En proceso',\n                    null"));

echo "\n=== Numeración de solicitudes ===\n";
checkLine('Backend expone display_number', contains($root . '/app/Services/TicketDetailService.php', "'display_number'"));
checkLine('UI usa display_number', contains($root . '/public/assets/js/modules/ticket-detail.js', 'request.display_number || request.id'));

echo "\n=== Historial compacto ===\n";
checkLine('Historial inicia en bloques de 5', contains($root . '/public/assets/js/modules/ticket-detail.js', 'Math.min(5, items.length)'));
checkLine('Botón Cargar 5 más', contains($root . '/public/assets/js/modules/ticket-detail.js', "button.textContent = 'Cargar 5 más'"));
checkLine('Contador Mostrando X de Y', contains($root . '/public/assets/js/modules/ticket-detail.js', 'Mostrando ${visibleCount} de ${items.length} eventos'));

echo "\n=== Campana de notificaciones ===\n";
checkLine('Endpoint lectura individual', is_file($root . '/public/api/notifications/read.php'));
checkLine('Endpoint marcar todas', is_file($root . '/public/api/notifications/read-all.php'));
checkLine('Lectura condicionada por user_id', contains($root . '/app/Services/NotificationService.php', 'WHERE id = :id AND user_id = :user_id'));
checkLine('Badge basado en read_at NULL', contains($root . '/app/Services/DashboardService.php', 'n.read_at IS NULL'));
checkLine('UI marca notificación al abrir', contains($root . '/public/assets/js/components/notificationsMenu.js', './api/notifications/read.php'));
checkLine('UI permite marcar todas como leídas', contains($root . '/public/assets/js/components/notificationsMenu.js', 'Marcar todas como leídas'));

try {
    $services = require $root . '/bootstrap/app.php';
    /** @var PDO $pdo */
    $pdo = $services['pdo'];

    echo "\n=== Estado actual de notificaciones Reporter ===\n";
    $statement = $pdo->query(
        "SELECT u.id, CONCAT_WS(' ', u.first_name, u.last_name) AS full_name,
                COUNT(n.id) AS total_notifications,
                SUM(CASE WHEN n.read_at IS NULL THEN 1 ELSE 0 END) AS unread_total
         FROM users u
         INNER JOIN user_roles ur ON ur.user_id = u.id
         INNER JOIN roles r ON r.id = ur.role_id AND r.code = 'reporter'
         LEFT JOIN notifications n ON n.user_id = u.id
         WHERE u.status = 'active'
         GROUP BY u.id, u.first_name, u.last_name
         ORDER BY u.id"
    );
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        printf(
            "user=%-4d %-28s total=%-4d unread_db=%d\n",
            (int) $row['id'],
            (string) $row['full_name'],
            (int) $row['total_notifications'],
            (int) $row['unread_total']
        );
    }

    echo "\n=== Tickets con múltiples solicitudes ===\n";
    $approval = $pdo->query(
        "SELECT t.folio, COUNT(ar.id) AS requests, GROUP_CONCAT(ar.id ORDER BY ar.requested_at, ar.id SEPARATOR ',') AS internal_ids
         FROM ticket_approval_requests ar
         INNER JOIN tickets t ON t.id = ar.ticket_id
         GROUP BY t.id, t.folio
         HAVING COUNT(ar.id) > 1
         ORDER BY t.id DESC
         LIMIT 10"
    );
    foreach ($approval->fetchAll(PDO::FETCH_ASSOC) as $row) {
        printf(
            "%s | solicitudes visibles=1..%d | ids internos=%s\n",
            (string) $row['folio'],
            (int) $row['requests'],
            (string) $row['internal_ids']
        );
    }
} catch (Throwable $exception) {
    echo "\nINFO: no fue posible ejecutar las comprobaciones de base de datos: {$exception->getMessage()}\n";
}

$ok = !in_array(false, $checks, true);
echo "\n" . ($ok ? 'VALIDACIÓN CORRECTA' : 'VALIDACIÓN CON PUNTOS A REVISAR') . "\n";
exit($ok ? 0 : 1);
