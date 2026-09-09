<?php

declare(strict_types=1);

use App\Core\Http;
use App\Security\Csrf;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';

Http::requireMethod('POST');
$input = Http::jsonInput();
Csrf::validate($input);

$result = $services['password_reset']->request(
    (string) ($input['email'] ?? '')
);

Http::json([
    'ok' => true,
    'data' => $result,
]);
