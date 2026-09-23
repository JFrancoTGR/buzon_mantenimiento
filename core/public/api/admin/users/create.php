<?php

declare(strict_types=1);

use App\Core\Http;
use EUTools\Shared\Security\Csrf;

$services = require dirname(__DIR__, 4) . '/bootstrap/app.php';

Http::requireMethod('POST');

$input = Http::jsonInput(16384);

Csrf::validate($input);

$user = $services['auth']->currentUser();

$createdUser = $services['user_admin']->createInvitedUser(
    $user,
    $input
);

Http::json([
    'ok' => true,
    'data' => [
        'user' => $createdUser,
    ],
], 201);
