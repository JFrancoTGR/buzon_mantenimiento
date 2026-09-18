<?php

declare(strict_types=1);

use App\Services\AccountService;

$services = require dirname(__DIR__) . '/bootstrap/app.php';

$root = dirname(__DIR__);
$checks = [
    'account_service_registered' => isset($services['account']) && $services['account'] instanceof AccountService,
    'profile_page_exists' => is_file($root . '/public/profile.html'),
    'profile_module_exists' => is_file($root . '/public/assets/js/modules/profile.js'),
    'profile_styles_exist' => is_file($root . '/public/assets/css/profile.css'),
    'profile_api_exists' => is_file($root . '/public/api/account/profile.php'),
    'profile_update_api_exists' => is_file($root . '/public/api/account/update-profile.php'),
    'change_password_available' => method_exists($services['auth'], 'changePassword'),
    'profile_route_registered' => str_contains(
        (string) file_get_contents($root . '/.htaccess'),
        '|profile)'
    ),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));

echo json_encode([
    'ok' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($failed === [] ? 0 : 1);
