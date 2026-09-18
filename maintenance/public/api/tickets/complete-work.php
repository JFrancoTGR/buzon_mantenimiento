<?php

declare(strict_types=1);

use App\Core\Http;
use App\Exceptions\HttpException;
use App\Security\Csrf;
use App\Services\AuthorizationService;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';
Http::requireMethod('POST');
$user = $services['auth']->currentUser();
AuthorizationService::requirePasswordChanged($user);
Csrf::validate();

$ticketId = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT);
$rowVersion = filter_var($_POST['row_version'] ?? null, FILTER_VALIDATE_INT);
if (!is_int($ticketId) || $ticketId < 1) {
    throw new HttpException(404, 'ticket_not_found', 'El ticket no existe.');
}
if (!is_int($rowVersion) || $rowVersion < 1) {
    throw new HttpException(422, 'invalid_row_version', 'La versión del ticket no es válida.');
}

$result = $services['ticket_detail']->completeWork(
    $user,
    $ticketId,
    (string) ($_POST['resolution_summary'] ?? ''),
    isset($_POST['observations']) ? (string) $_POST['observations'] : null,
    $_FILES['completion_evidence'] ?? null,
    $rowVersion
);

Http::json(['ok' => true, 'data' => $result]);
