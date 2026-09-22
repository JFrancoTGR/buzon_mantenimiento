<?php

declare(strict_types=1);

use App\Core\Http;
use App\Services\AuthorizationService;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';
Http::requireMethod('GET');
$user = $services['maintenance_context']->currentUser();
AuthorizationService::requirePasswordChanged($user);
$ticketId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($ticketId) || $ticketId < 1) {
    throw new App\Exceptions\HttpException(404, 'ticket_not_found', 'El ticket no existe.');
}
Http::json(['ok' => true, 'data' => $services['ticket_detail']->detail($user, $ticketId)]);
