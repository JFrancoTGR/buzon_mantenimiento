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
Http::json(['ok' => true, 'data' => $services['notifications']->markAllRead($user)]);
