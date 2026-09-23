<?php

declare(strict_types=1);

use App\Core\Http;
use EUTools\Shared\Security\Csrf;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';

Http::requireMethod('GET');

$user = $services['auth']->currentUser();

Http::json([
    'ok' => true,
    'data' => [
        'user' => $user,
        'csrf_token' => Csrf::token(),
    ],
]);
