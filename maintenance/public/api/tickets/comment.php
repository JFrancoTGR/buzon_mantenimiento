<?php

declare(strict_types=1);

use App\Core\Http;
use EUTools\Shared\Security\Csrf;
use App\Services\AuthorizationService;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';
Http::requireMethod('POST');
$user = $services['maintenance_context']->currentUser();
AuthorizationService::requirePasswordChanged($user);
Csrf::validate();
$input = Http::jsonInput(16384);
$ticketId = filter_var($input['ticket_id'] ?? null, FILTER_VALIDATE_INT);
if (!is_int($ticketId) || $ticketId < 1) {
    throw new App\Exceptions\HttpException(404, 'ticket_not_found', 'El ticket no existe.');
}
$result = $services['ticket_detail']->addComment($user, $ticketId, (string) ($input['body'] ?? ''));
Http::json(['ok' => true, 'data' => $result], 201);
