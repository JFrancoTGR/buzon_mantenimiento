<?php

declare(strict_types=1);

use App\Core\Http;
use EUTools\Shared\Security\Csrf;

require dirname(__DIR__, 3) . '/bootstrap/app.php';

Http::requireMethod('GET');

Http::json([
    'ok' => true,
    'data' => [
        'csrf_token' => Csrf::token(),
    ],
]);
