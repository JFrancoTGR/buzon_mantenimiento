<?php

declare(strict_types=1);

use App\Core\Http;

$services = require dirname(__DIR__, 4) . '/bootstrap/app.php';

Http::requireMethod('GET');

$user = $services['auth']->currentUser();

Http::json([
    'ok' => true,
    'data' => $services['user_admin']->listUsers(
        $user,
        $_GET
    ),
]);