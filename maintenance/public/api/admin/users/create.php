<?php

declare(strict_types=1);

use App\Core\Http;
use App\Security\Csrf;
use App\Services\AuthorizationService;

$services = require dirname(__DIR__, 4) . '/bootstrap/app.php';

Http::requireMethod('POST');
$input = Http::jsonInput(16384);
Csrf::validate($input);

$user = $services['auth']->currentUser();
AuthorizationService::requirePasswordChanged($user);
AuthorizationService::requirePermission($user, 'user.manage');
AuthorizationService::requirePermission($user, 'role.manage');

Http::json([
    'ok' => true,
    'data' => $services['user_admin']->createInvitedUser($user, $input),
], 201);
