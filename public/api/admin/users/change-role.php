<?php

declare(strict_types=1);

use App\Core\Http;
use App\Security\Csrf;
use App\Services\AuthorizationService;

$services = require dirname(__DIR__, 4) . '/bootstrap/app.php';

Http::requireMethod('POST');
$input = Http::jsonInput(8192);
Csrf::validate($input);

$user = $services['auth']->currentUser();
AuthorizationService::requirePasswordChanged($user);
AuthorizationService::requirePermission($user, 'user.manage');
AuthorizationService::requirePermission($user, 'role.manage');
$userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
if (!is_int($userId) || $userId < 1) {
    Http::json(['ok' => false, 'error' => ['code' => 'user_not_found', 'message' => 'El usuario no existe.']], 404);
}

Http::json([
    'ok' => true,
    'data' => $services['user_admin']->changeRole($user, $userId, $input),
]);
