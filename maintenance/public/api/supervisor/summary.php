<?php

declare(strict_types=1);

use App\Core\Http;
use App\Services\AuthorizationService;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';
Http::requireMethod('GET');
$user = $services['auth']->currentUser();
AuthorizationService::requirePasswordChanged($user);
Http::json(['ok' => true, 'data' => $services['supervisor']->summary($user)]);
