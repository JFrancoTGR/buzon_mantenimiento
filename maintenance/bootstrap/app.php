<?php

declare(strict_types=1);

use App\Config\Env;
use App\Core\Database;
use App\Core\Http;
use App\Exceptions\HttpException;
use App\Services\AuditService;
use App\Services\AuthorizationDecisionService;
use App\Services\AuthorizationRequestService;
use App\Services\DashboardService;
use App\Services\MailerService;
use App\Services\MaintenanceContextService;
use App\Services\NotificationService;
use App\Services\QuotationService;
use App\Services\SupervisorService;
use App\Services\TicketDetailService;
use App\Services\TicketService;
use EUTools\Shared\Mail\Mailer as SharedMailer;
use EUTools\Shared\Mail\TemplateRegistry;
use EUTools\Shared\Security\Csrf;
use EUTools\Shared\Security\CsrfException;
use EUTools\Shared\Security\SessionRuntime;

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
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = ROOT_PATH . '/app/' . str_replace('\\', '/', $relative) . '.php';

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
SessionRuntime::start([
    'name' => Env::get(
        'SESSION_NAME',
        'EUTOOLSSESSID'
    ),
    'lifetime_minutes' => Env::int(
        'SESSION_LIFETIME_MINUTES',
        30
    ),
    'secure_cookie' => Env::bool(
        'SESSION_SECURE_COOKIE',
        true
    ),
    'same_site' => Env::get(
        'SESSION_SAME_SITE',
        'Lax'
    ),
    'cookie_path' => Env::get(
        'SESSION_COOKIE_PATH',
        '/'
    ),
]);

Csrf::token();

$pdo = Database::connection();

$auditService = new AuditService($pdo);
$mailerService = new MailerService($pdo);
$mailTemplateRegistry = new TemplateRegistry();

$maintenanceContextService =
    new MaintenanceContextService($pdo);

$sharedMailer = new SharedMailer(
    $pdo,
    $mailTemplateRegistry,
    [
        'transport' => strtolower(
            Env::get('MAIL_TRANSPORT', 'smtp')
        ),
        'smtp_host' => Env::get('SMTP_HOST', ''),
        'smtp_port' => Env::int('SMTP_PORT', 587),
        'smtp_auth' => Env::bool('SMTP_AUTH', true),
        'smtp_username' => Env::get('SMTP_USERNAME', ''),
        'smtp_password' => Env::get('SMTP_PASSWORD', ''),
        'smtp_encryption' => strtolower(
            Env::get('SMTP_ENCRYPTION', 'tls')
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
            'Plataforma de Mantenimiento'
        ),
        'log_path' => ROOT_PATH . '/storage/logs/mail.log',
    ]
);

$dashboardService =
    new DashboardService($pdo);

$notificationService =
    new NotificationService($pdo);

$ticketService =
    new TicketService(
        $pdo,
        $auditService,
        $mailerService
    );

$ticketDetailService =
    new TicketDetailService(
        $pdo,
        $auditService,
        $mailerService
    );

$quotationService =
    new QuotationService(
        $pdo,
        $auditService,
        $mailerService
    );

$authorizationRequestService =
    new AuthorizationRequestService(
        $pdo,
        $auditService,
        $mailerService
    );

$authorizationDecisionService =
    new AuthorizationDecisionService(
        $pdo,
        $auditService,
        $mailerService
    );

$supervisorService =
    new SupervisorService($pdo);

set_exception_handler(
    static function (
        Throwable $exception
    ) use ($debug): void {
        if (
            $exception instanceof HttpException
            || $exception instanceof CsrfException
        ) {
            Http::json(
                [
                    'ok' => false,
                    'error' => [
                        'code' => $exception->errorCode,
                        'message' => $exception->getMessage(),
                    ],
                ],
                $exception->status
            );
        }

        error_log((string) $exception);

        $payload = [
            'ok' => false,
            'error' => [
                'code' => 'internal_error',
                'message' => 'Ocurrió un error interno.',
            ],
        ];

        if ($debug) {
            $payload['debug'] = [
                'type' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        Http::json($payload, 500);
    }
);

return [
    'pdo' => $pdo,
    'audit' => $auditService,
    'mailer' => $mailerService,
    'maintenance_context' => $maintenanceContextService,
    'shared_mailer' => $sharedMailer,
    'dashboard' => $dashboardService,
    'notifications' => $notificationService,
    'tickets' => $ticketService,
    'ticket_detail' => $ticketDetailService,
    'quotations' => $quotationService,
    'authorization_requests' =>
        $authorizationRequestService,
    'authorization_decisions' =>
        $authorizationDecisionService,
    'supervisor' => $supervisorService,
];