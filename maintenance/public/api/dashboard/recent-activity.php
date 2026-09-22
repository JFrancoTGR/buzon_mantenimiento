<?php

declare(strict_types=1);

use App\Core\Http;
use App\Services\AuthorizationService;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';

Http::requireMethod('GET');
$user = $services['maintenance_context']->currentUser();
AuthorizationService::requirePasswordChanged($user);

Http::json([
    'ok' => true,
    'data' => [
        'items' => $services['dashboard']->recentActivity($user),
    ],
]);
