<?php

declare(strict_types=1);

use App\Core\Http;
use App\Security\Csrf;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';

Http::requireMethod('POST');

$input = Http::jsonInput(16384);

Csrf::validate($input);

$result = $services['user_invitations']->accept(
    (string) ($input['token'] ?? ''),
    (string) ($input['password'] ?? ''),
    (string) ($input['password_confirmation'] ?? '')
);

Http::json([
    'ok' => true,
    'data' =>
        $result
        + [
            'csrf_token' => Csrf::token(),
        ],
]);