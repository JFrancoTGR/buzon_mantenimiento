<?php

declare(strict_types=1);

use App\Config\Env;
use App\Core\Database;
use App\Core\Http;
use App\Exceptions\HttpException;
use App\Security\SessionManager;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\HubService;
use App\Services\RegistrationService;
use App\Services\UserAdminService;
use App\Services\UserInvitationService;
use App\Services\WebAuthService;
use EUTools\Shared\Mail\Mailer as SharedMailer;
use EUTools\Shared\Mail\TemplateRegistry;

const ROOT_PATH = __DIR__ . '/..';

$composerAutoload = ROOT_PATH . '/vendor/autoload.php';

if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

$sharedAutoload = dirname(__DIR__, 2) . '/shared/autoload.php';

if (is_file($sharedAutoload)) {
    require_once $sharedAutoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));

    $path = ROOT_PATH
        . '/app/'
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

$authService = new AuthService(
    $pdo,
    $auditService
);

$webAuthService = new WebAuthService(
    $authService
);

$hubService = new HubService($pdo);

$mailTemplateRegistry = new TemplateRegistry();

$sharedMailer = new SharedMailer(
    $pdo,
    $mailTemplateRegistry,
    [
        'transport' => strtolower(
            Env::get('MAIL_TRANSPORT', 'smtp')
        ),

        'smtp_host' => Env::get(
            'SMTP_HOST',
            ''
        ),

        'smtp_port' => Env::int(
            'SMTP_PORT',
            587
        ),

        'smtp_auth' => Env::bool(
            'SMTP_AUTH',
            true
        ),

        'smtp_username' => Env::get(
            'SMTP_USERNAME',
            ''
        ),

        'smtp_password' => Env::get(
            'SMTP_PASSWORD',
            ''
        ),

        'smtp_encryption' => strtolower(
            Env::get(
                'SMTP_ENCRYPTION',
                'tls'
            )
        ),

        'smtp_timeout_seconds' => Env::int(
            'SMTP_TIMEOUT_SECONDS',
            15
        ),

        'from_address' => Env::get(
            'SMTP_FROM_ADDRESS',
            ''
        ),

        'from_name' => Env::get(
            'SMTP_FROM_NAME',
            'EU Tools'
        ),

        'log_path' =>
            ROOT_PATH . '/storage/logs/mail.log',
    ]
);

$registrationService = new RegistrationService(
    $pdo,
    $auditService,
    $sharedMailer,
    $authService
);

$userInvitationService = new UserInvitationService(
    $pdo,
    $auditService,
    $sharedMailer,
    $authService
);

$userAdminService = new UserAdminService(
    $pdo,
    $auditService,
    $userInvitationService
);

set_exception_handler(
    static function (
        Throwable $exception
    ) use ($debug): void {
        if ($exception instanceof HttpException) {
            Http::json([
                'ok' => false,
                'error' => [
                    'code' =>
                        $exception->errorCode,

                    'message' =>
                        $exception->getMessage(),
                ],
            ], $exception->status);
        }

        error_log((string) $exception);

        $payload = [
            'ok' => false,
            'error' => [
                'code' => 'internal_error',
                'message' =>
                    'Ocurrió un error interno.',
            ],
        ];

        if ($debug) {
            $payload['debug'] = [
                'type' => $exception::class,
                'message' =>
                    $exception->getMessage(),
            ];
        }

        Http::json($payload, 500);
    }
);

return [
    'pdo' => $pdo,
    'audit' => $auditService,
    'auth' => $authService,
    'webAuth' => $webAuthService,
    'hub' => $hubService,
    'registration' => $registrationService,
    'user_admin' => $userAdminService,
    'shared_mailer' => $sharedMailer,
    'user_invitations' =>
        $userInvitationService,
];