<?php

declare(strict_types=1);

use App\Core\Http;
use App\Services\AuthorizationService;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';
Http::requireMethod('GET');
$user = $services['maintenance_context']->currentUser();
AuthorizationService::requirePasswordChanged($user);

$filters = [
    'status' => $_GET['status'] ?? '',
    'route' => $_GET['route'] ?? '',
    'location_id' => $_GET['location_id'] ?? null,
    'priority' => $_GET['priority'] ?? '',
    'attention' => $_GET['attention'] ?? false,
    'search' => $_GET['search'] ?? '',
];

Http::json(['ok' => true, 'data' => $services['supervisor']->tickets($user, $filters)]);
