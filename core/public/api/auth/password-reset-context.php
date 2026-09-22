<?php

declare(strict_types=1);

use App\Core\Http;
use App\Security\Csrf;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';

Http::requireMethod('POST');

$input = Http::jsonInput(8192);

Csrf::validate($input);

Http::json([
    'ok' => true,
    'data' => $services['password_reset']->inspect(
        (string) ($input['token'] ?? '')
    ),
]);