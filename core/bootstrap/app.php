<?php

declare (strict_types = 1);

use App\Config\Env;
use App\Core\Database;
use App\Core\Http;
use App\Exceptions\HttpException;
use App\Security\SessionManager;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\WebAuthService;
use App\Services\HubService;
use App\Services\UserAdminService;

const ROOT_PATH = __DIR__ . '/..';

$composerAutoload = ROOT_PATH . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));

    $path = ROOT_PATH . '/app/'
    . str_replace('\\', '/', $relative)
        . '.php';

    if (is_file($path)) {
        require $path;
    }
});

Env::load(ROOT_PATH . '/.env');

date_default_timezone_set('UTC');

$debug = Env::bool('APP_DEBUG', false);

ini_set('display_errors', $debug ? '1' : '0');
error_reporting(E_ALL);

Http::applySecurityHeaders();
SessionManager::start();

$pdo = Database::connection();

$auditService = new AuditService($pdo);
$authService  = new AuthService($pdo, $auditService);
$webAuthService = new WebAuthService($authService);
$hubService = new HubService($pdo);
$userAdminService = new UserAdminService($pdo);

set_exception_handler(
    static function (Throwable $exception) use ($debug): void {
        if ($exception instanceof HttpException) {
            Http::json([
                'ok'    => false,
                'error' => [
                    'code'    => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ],
            ], $exception->status);
        }

        error_log((string) $exception);

        $payload = [
            'ok'    => false,
            'error' => [
                'code'    => 'internal_error',
                'message' => 'Ocurrió un error interno.',
            ],
        ];

        if ($debug) {
            $payload['debug'] = [
                'type'    => $exception::class,
                'message' => $exception->getMessage(),
            ];
        }

        Http::json($payload, 500);
    }
);

return [
    'pdo'   => $pdo,
    'audit' => $auditService,
    'auth'  => $authService,
    'webAuth' => $webAuthService,
    'hub' => $hubService,
    'user_admin' => $userAdminService,
];
