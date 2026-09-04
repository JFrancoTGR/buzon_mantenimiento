<?php

declare(strict_types=1);

use App\Core\Http;
use App\Security\Csrf;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';

Http::requireMethod('POST');
$input = Http::jsonInput(8192);
Csrf::validate($input);

$result = $services['registration']->resendVerification(
    (string) ($input['email'] ?? ''),
    isset($input['location']) ? (string) $input['location'] : null
);

Http::json([
    'ok' => true,
    'data' => $result + [
        'message' => 'Si la cuenta está pendiente, enviaremos un nuevo enlace cuando sea posible.',
    ],
], 202);
