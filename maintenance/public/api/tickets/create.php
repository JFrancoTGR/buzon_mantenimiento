<?php

declare(strict_types=1);

use App\Core\Http;
use App\Security\Csrf;
use App\Services\AuthorizationService;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';

Http::requireMethod('POST');
Csrf::validate($_POST);

$user = $services['maintenance_context']->currentUser();
AuthorizationService::requirePasswordChanged($user);
AuthorizationService::requirePermission($user, 'ticket.create');

$result = $services['tickets']->create($user, $_POST, $_FILES);

Http::json([
    'ok' => true,
    'data' => $result,
], 201);
