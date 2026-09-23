<?php

declare(strict_types=1);

use App\Exceptions\HttpException;

$services = require dirname(__DIR__) . '/bootstrap/app.php';

header('Cache-Control: no-store, private');

function redirectTo(string $location): void
{
    header('Location: ' . $location, true, 302);
    exit;
}

$returnPath = '/maintenance/';

try {
    $user = $services['maintenance_context']->currentUser();
} catch (HttpException $exception) {
    if ($exception->status === 401) {
        redirectTo(
            '/login?return=' . rawurlencode($returnPath)
        );
    }

    if (
        $exception->status === 403
        && $exception->errorCode ===
            'application_access_denied'
    ) {
        redirectTo('/');
    }

    throw $exception;
}

if ((bool) ($user['must_change_password'] ?? false)) {
    redirectTo(
        '/change-password?return='
        . rawurlencode($returnPath)
    );
}

redirectTo('/maintenance/dashboard.html');