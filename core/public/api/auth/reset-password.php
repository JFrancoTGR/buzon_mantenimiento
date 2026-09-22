<?php

declare(strict_types=1);

use App\Core\Http;
use App\Security\Csrf;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';

Http::requireMethod('POST');

$input = Http::jsonInput(8192);

Csrf::validate($input);

$result = $services['password_reset']->reset(
    (string) ($input['token'] ?? ''),
    (string) ($input['password'] ?? ''),
    (string) ($input['password_confirmation'] ?? '')
);

Http::json([
    'ok' => true,
    'data' => $result,
]);