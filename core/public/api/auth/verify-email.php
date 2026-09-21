<?php

declare(strict_types=1);

use App\Core\Http;
use App\Security\Csrf;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';

Http::requireMethod('POST');
$input = Http::jsonInput(8192);
Csrf::validate($input);

$result = $services['registration']->verifyEmail(
    (string) ($input['token'] ?? ''),
    isset($input['next']) ? (string) $input['next'] : null
);

Http::json([
    'ok' => true,
    'data' => $result + [
        'csrf_token' => Csrf::token(),
    ],
]);
