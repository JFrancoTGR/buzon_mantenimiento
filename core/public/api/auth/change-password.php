<?php

declare(strict_types=1);

use App\Core\Http;
use App\Security\Csrf;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';

Http::requireMethod('POST');

$input = Http::jsonInput(8192);

Csrf::validate($input);

$result = $services['account']->changePassword(
    (string) ($input['current_password'] ?? ''),
    (string) ($input['new_password'] ?? ''),
    (string) ($input['new_password_confirmation'] ?? '')
);

Http::json([
    'ok' => true,
    'data' => $result,
]);