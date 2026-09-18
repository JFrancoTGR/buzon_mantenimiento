<?php

declare(strict_types=1);

use App\Core\Http;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';

Http::requireMethod('GET');

$user = $services['auth']->currentUser();

$applications = $services['hub']->listApplications(
    (int) $user['id']
);

Http::json([
    'ok' => true,
    'data' => [
        'applications' => $applications,
    ],
]);