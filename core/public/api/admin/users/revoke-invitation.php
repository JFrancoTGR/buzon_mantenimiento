<?php

declare(strict_types=1);

use App\Core\Http;
use App\Security\Csrf;

$services = require dirname(__DIR__, 4) . '/bootstrap/app.php';

Http::requireMethod('POST');

$input = Http::jsonInput(8192);

Csrf::validate($input);

$user = $services['auth']->currentUser();

$result = $services['user_admin']->revokeInvitation(
    $user,
    (int) ($input['user_id'] ?? 0)
);

Http::json([
    'ok' => true,
    'data' => $result,
]);