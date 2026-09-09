<?php

declare(strict_types=1);

use App\Services\PasswordResetService;

$services = require dirname(__DIR__) . '/bootstrap/app.php';

$root = dirname(__DIR__);
$pdo = $services['pdo'];

$checks = [
    'password_reset_service_registered' =>
        isset($services['password_reset'])
        && $services['password_reset'] instanceof PasswordResetService,

    'forgot_password_page_exists' =>
        is_file($root . '/public/forgot-password.html'),

    'reset_password_page_exists' =>
        is_file($root . '/public/reset-password.html'),

    'forgot_password_api_exists' =>
        is_file($root . '/public/api/auth/forgot-password.php'),

    'password_reset_context_api_exists' =>
        is_file($root . '/public/api/auth/password-reset-context.php'),

    'reset_password_api_exists' =>
        is_file($root . '/public/api/auth/reset-password.php'),

    'forgot_password_js_exists' =>
        is_file($root . '/public/assets/js/modules/forgot-password.js'),

    'reset_password_js_exists' =>
        is_file($root . '/public/assets/js/modules/reset-password.js'),

    'password_visibility_component_exists' =>
        is_file($root . '/public/assets/js/components/passwordVisibility.js'),

    'mailer_reset_request_available' =>
        method_exists($services['mailer'], 'sendPasswordResetEmail'),

    'mailer_reset_completed_available' =>
        method_exists($services['mailer'], 'sendPasswordResetCompletedNotification'),

    'login_recovery_link_registered' =>
        str_contains(
            (string) file_get_contents($root . '/public/login.html'),
            'href="./forgot-password.html"'
        ),

    'forgot_password_route_registered' =>
        str_contains(
            (string) file_get_contents($root . '/.htaccess'),
            'forgot-password'
        ),

    'reset_password_route_registered' =>
        str_contains(
            (string) file_get_contents($root . '/.htaccess'),
            'reset-password'
        ),
];

$columns = $pdo->query(
    "SELECT column_name
     FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'password_reset_tokens'
       AND column_name IN (
           'id',
           'user_id',
           'token_hash',
           'expires_at',
           'used_at',
           'revoked_at',
           'requested_ip',
           'user_agent',
           'created_at'
       )"
)->fetchAll(PDO::FETCH_COLUMN);

$checks['password_reset_schema_ready'] = count(array_unique(array_map('strval', $columns))) === 9;

$uniqueTokenHash = $pdo->query(
    "SELECT COUNT(*)
     FROM (
         SELECT index_name
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = 'password_reset_tokens'
           AND index_name = 'uq_password_reset_token_hash'
           AND non_unique = 0
         GROUP BY index_name
     ) x"
)->fetchColumn();

$checks['password_reset_token_hash_unique'] = (int) $uniqueTokenHash === 1;

$migration16 = $pdo->prepare(
    "SELECT COUNT(*)
     FROM schema_migrations
     WHERE version = '16'
       AND name = 'password_reset_token_hardening'"
);
$migration16->execute();

$checks['migration_16_registered'] = (int) $migration16->fetchColumn() === 1;

$failed = array_keys(array_filter(
    $checks,
    static fn(bool $ok): bool => !$ok
));

echo json_encode([
    'ok' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($failed === [] ? 0 : 1);
