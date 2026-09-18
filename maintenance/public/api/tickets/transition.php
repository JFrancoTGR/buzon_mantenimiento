<?php

declare(strict_types=1);

use App\Core\Http;
use App\Security\Csrf;
use App\Services\AuthorizationService;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';
Http::requireMethod('POST');
$user = $services['auth']->currentUser();
AuthorizationService::requirePasswordChanged($user);
Csrf::validate();
$input = Http::jsonInput(16384);
$ticketId = filter_var($input['ticket_id'] ?? null, FILTER_VALIDATE_INT);
$rowVersion = filter_var($input['row_version'] ?? null, FILTER_VALIDATE_INT);
if (!is_int($ticketId) || $ticketId < 1) {
    throw new App\Exceptions\HttpException(404, 'ticket_not_found', 'El ticket no existe.');
}
if (!is_int($rowVersion) || $rowVersion < 1) {
    throw new App\Exceptions\HttpException(422, 'invalid_row_version', 'La versión del ticket no es válida.');
}
$result = $services['ticket_detail']->transition(
    $user,
    $ticketId,
    trim((string) ($input['to_status'] ?? '')),
    isset($input['comment']) ? (string) $input['comment'] : null,
    $rowVersion
);
Http::json(['ok' => true, 'data' => $result]);
