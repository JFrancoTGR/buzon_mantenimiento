<?php

declare (strict_types = 1);

use App\Config\Env;
use App\Core\Database;
use App\Core\Http;
use App\Exceptions\HttpException;
use App\Security\SessionManager;
use App\Services\AccountService;
use App\Services\AuditService;
use App\Services\AuthorizationDecisionService;
use App\Services\AuthorizationRequestService;
use App\Services\AuthService;
use App\Services\DashboardService;
use App\Services\MailerService;
use App\Services\NotificationService;
use App\Services\PasswordResetService;
use App\Services\QuotationService;
use App\Services\RegistrationService;
use App\Services\SupervisorService;
use App\Services\TicketDetailService;
use App\Services\TicketService;
use App\Services\UserAdminService;
use App\Services\UserInvitationService;
use EUTools\Shared\Mail\Mailer as SharedMailer;
use EUTools\Shared\Mail\TemplateRegistry;

const ROOT_PATH = __DIR__ . '/..';

$composerAutoload = ROOT_PATH . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

$sharedAutoload = dirname(ROOT_PATH) . '/shared/autoload.php';

if (is_file($sharedAutoload)) {
    require_once $sharedAutoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path     = ROOT_PATH . '/app/' . str_replace('\\', '/', $relative) . '.php';
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

$pdo                  = Database::connection();
$auditService         = new AuditService($pdo);
$authService          = new AuthService($pdo, $auditService);
$accountService       = new AccountService($pdo, $auditService, $authService);
$mailerService        = new MailerService($pdo);
$mailTemplateRegistry = new TemplateRegistry();

$sharedMailer = new SharedMailer(
    $pdo,
    $mailTemplateRegistry,
    [
        'transport'            => strtolower(
            Env::get('MAIL_TRANSPORT', 'smtp')
        ),
        'smtp_host'            => Env::get('SMTP_HOST', ''),
        'smtp_port'            => Env::int('SMTP_PORT', 587),
        'smtp_auth'            => Env::bool('SMTP_AUTH', true),
        'smtp_username'        => Env::get('SMTP_USERNAME', ''),
        'smtp_password'        => Env::get('SMTP_PASSWORD', ''),
        'smtp_encryption'      => strtolower(
            Env::get('SMTP_ENCRYPTION', 'tls')
        ),
        'smtp_timeout_seconds' => Env::int(
            'SMTP_TIMEOUT_SECONDS',
            15
        ),
        'from_address'         => Env::get(
            'SMTP_FROM_ADDRESS',
            ''
        ),
        'from_name'            => Env::get(
            'SMTP_FROM_NAME',
            'Plataforma de Mantenimiento'
        ),
        'log_path'             => ROOT_PATH . '/storage/logs/mail.log',
    ]
);
$passwordResetService  = new PasswordResetService($pdo, $auditService, $mailerService);
$userInvitationService = new UserInvitationService(
    $pdo,
    $auditService,
    $authService,
    $mailerService
);
$userAdminService = new UserAdminService(
    $pdo,
    $auditService,
    $userInvitationService
);
$registrationService = new RegistrationService(
    $pdo,
    $auditService,
    $authService,
    $mailerService
);
$dashboardService             = new DashboardService($pdo);
$notificationService          = new NotificationService($pdo);
$ticketService                = new TicketService($pdo, $auditService, $mailerService);
$ticketDetailService          = new TicketDetailService($pdo, $auditService, $mailerService);
$quotationService             = new QuotationService($pdo, $auditService, $mailerService);
$authorizationRequestService  = new AuthorizationRequestService($pdo, $auditService, $mailerService);
$authorizationDecisionService = new AuthorizationDecisionService($pdo, $auditService, $mailerService);
$supervisorService            = new SupervisorService($pdo);

set_exception_handler(static function (Throwable $exception) use ($debug): void {
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
            'file'    => $exception->getFile(),
            'line'    => $exception->getLine(),
        ];
    }

    Http::json($payload, 500);
});

return [
    'pdo'                     => $pdo,
    'audit'                   => $auditService,
    'auth'                    => $authService,
    'account'                 => $accountService,
    'mailer'                  => $mailerService,
    'shared_mailer' => $sharedMailer,
    'password_reset'          => $passwordResetService,
    'user_invitations'        => $userInvitationService,
    'user_admin'              => $userAdminService,
    'registration'            => $registrationService,
    'dashboard'               => $dashboardService,
    'notifications'           => $notificationService,
    'tickets'                 => $ticketService,
    'ticket_detail'           => $ticketDetailService,
    'quotations'              => $quotationService,
    'authorization_requests'  => $authorizationRequestService,
    'authorization_decisions' => $authorizationDecisionService,
    'supervisor'              => $supervisorService,
];
