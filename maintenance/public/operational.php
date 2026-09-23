<?php

declare(strict_types=1);

use App\Exceptions\HttpException;
use App\Services\AuthorizationService;

$services = require dirname(__DIR__) . '/bootstrap/app.php';

header('Cache-Control: no-store, private');

$views = [
    'dashboard' => 'dashboard.html',
    'new-ticket' => 'new-ticket.html',
    'ticket' => 'ticket.html',
    'tickets' => 'tickets.html',
];

$view = isset($_GET['view'])
    ? (string) $_GET['view']
    : '';

$file = $views[$view] ?? null;

if ($file === null) {
    http_response_code(404);
    exit;
}

function redirectOperationalRequest(
    string $location
): void {
    header('Location: ' . $location, true, 302);
    exit;
}

$returnPath = $_SERVER['REQUEST_URI']
    ?? '/maintenance/';

if (
    !is_string($returnPath)
    || !str_starts_with(
        $returnPath,
        '/maintenance/'
    )
) {
    $returnPath = '/maintenance/';
}

try {
    $user =
        $services['maintenance_context']->currentUser();
} catch (HttpException $exception) {
    if ($exception->status === 401) {
        redirectOperationalRequest(
            '/login?return='
            . rawurlencode($returnPath)
        );
    }

    if (
        $exception->status === 403
        && $exception->errorCode ===
            'application_access_denied'
    ) {
        redirectOperationalRequest('/');
    }

    throw $exception;
}

if ((bool) ($user['must_change_password'] ?? false)) {
    redirectOperationalRequest(
        '/change-password?return='
        . rawurlencode($returnPath)
    );
}

if (
    $view === 'new-ticket'
    && !AuthorizationService::hasPermission(
        $user,
        'ticket.create'
    )
) {
    redirectOperationalRequest(
        '/maintenance/dashboard.html'
    );
}
header('Content-Type: text/html; charset=UTF-8');

readfile(__DIR__ . '/' . $file);
