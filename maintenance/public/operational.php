<?php

declare(strict_types=1);

use App\Exceptions\HttpException;
use App\Services\AuthorizationService;

$services = require dirname(__DIR__) . '/bootstrap/app.php';

header('Cache-Control: no-store, private');

$views = [
    'dashboard' =>
        dirname(__DIR__)
        . '/app/Views/operational/dashboard.php',

    'new-ticket' =>
        dirname(__DIR__)
        . '/app/Views/operational/new-ticket.php',

    'ticket' =>
        dirname(__DIR__)
        . '/app/Views/operational/ticket.php',

    'tickets' =>
        dirname(__DIR__)
        . '/app/Views/operational/tickets.php',
];

$view = isset($_GET['view'])
    ? (string) $_GET['view']
    : '';

$template = $views[$view] ?? null;

if (
    $template === null
    || !is_file($template)
) {
    http_response_code(404);
    exit;
}

function redirectOperationalRequest(
    string $location
): void {
    header('Location: ' . $location, true, 302);
    exit;
}

function maintenanceE(
    string $value
): string {
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

/**
 * @param array<string, mixed> $user
 * @param array<int, string> $permissions
 */
function maintenanceHasAnyPermission(
    array $user,
    array $permissions
): bool {
    foreach ($permissions as $permission) {
        if (
            AuthorizationService::hasPermission(
                $user,
                $permission
            )
        ) {
            return true;
        }
    }

    return false;
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

if (
    (bool) (
        $user['must_change_password']
        ?? false
    )
) {
    redirectOperationalRequest(
        '/change-password?return='
        . rawurlencode($returnPath)
    );
}

$canViewTickets =
    maintenanceHasAnyPermission(
        $user,
        [
            'ticket.view.own',
            'ticket.view.assigned',
            'ticket.view.all',
        ]
    );

$canCreateTicket =
    AuthorizationService::hasPermission(
        $user,
        'ticket.create'
    );

$canManageCatalogs =
    AuthorizationService::hasPermission(
        $user,
        'catalog.manage'
    );

$canViewAudit =
    AuthorizationService::hasPermission(
        $user,
        'audit.view'
    );

$showManagement =
    $canViewTickets
    || $canCreateTicket;

$showAdministration =
    $canManageCatalogs
    || $canViewAudit;

if (
    $view === 'new-ticket'
    && !$canCreateTicket
) {
    redirectOperationalRequest(
        '/maintenance/dashboard.html'
    );
}

if (
    in_array(
        $view,
        ['tickets', 'ticket'],
        true
    )
    && !$canViewTickets
) {
    redirectOperationalRequest(
        '/maintenance/dashboard.html'
    );
}

$activeNav = match ($view) {
    'ticket' => 'tickets',
    default => $view,
};

$firstName = trim(
    (string) (
        $user['first_name']
        ?? ''
    )
);

$lastName = trim(
    (string) (
        $user['last_name']
        ?? ''
    )
);

$fullName = trim(
    (string) (
        $user['full_name']
        ?? ''
    )
);

if ($fullName === '') {
    $fullName = trim(
        $firstName . ' ' . $lastName
    );
}

if ($fullName === '') {
    $fullName = 'Usuario';
}

$email = (string) (
    $user['email']
    ?? ''
);

$firstInitial =
    $firstName !== ''
        ? (
            function_exists('mb_substr')
                ? mb_substr(
                    $firstName,
                    0,
                    1
                )
                : substr(
                    $firstName,
                    0,
                    1
                )
        )
        : '';

$lastInitial =
    $lastName !== ''
        ? (
            function_exists('mb_substr')
                ? mb_substr(
                    $lastName,
                    0,
                    1
                )
                : substr(
                    $lastName,
                    0,
                    1
                )
        )
        : '';

$initials = strtoupper(
    $firstInitial
    . $lastInitial
);

if ($initials === '') {
    $initials = 'US';
}

header(
    'Content-Type: text/html; charset=UTF-8'
);

require $template;
