<?php

declare(strict_types=1);

use App\Core\Http;
use App\Services\AuthorizationService;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';

Http::requireMethod('GET');
$user = $services['auth']->currentUser();
AuthorizationService::requirePasswordChanged($user);
AuthorizationService::requirePermission($user, 'ticket.create');

$locationCode = isset($_GET['location']) ? trim((string) $_GET['location']) : null;

Http::json([
    'ok' => true,
    'data' => $services['tickets']->context($user, $locationCode),
]);
