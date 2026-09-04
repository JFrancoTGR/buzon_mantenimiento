<?php

declare(strict_types=1);

use App\Core\Http;
use App\Security\Csrf;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';

Http::requireMethod('POST');
$input = Http::jsonInput();
Csrf::validate($input);

$user = $services['auth']->login(
    (string) ($input['email'] ?? ''),
    (string) ($input['password'] ?? '')
);

Http::json([
    'ok' => true,
    'data' => [
        'user' => $user,
        'csrf_token' => Csrf::token(),
    ],
]);
