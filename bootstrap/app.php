<?php

declare(strict_types=1);

use App\Config\Env;
use App\Core\Database;
use App\Core\Http;
use App\Exceptions\HttpException;
use App\Security\SessionManager;
use App\Services\AuditService;
use App\Services\AuthorizationRequestService;
use App\Services\AuthorizationDecisionService;
use App\Services\AuthService;
use App\Services\DashboardService;
use App\Services\MailerService;
use App\Services\NotificationService;
use App\Services\RegistrationService;
use App\Services\QuotationService;
use App\Services\TicketService;
use App\Services\TicketDetailService;
use App\Services\SupervisorService;

const ROOT_PATH = __DIR__ . '/..';

$composerAutoload = ROOT_PATH . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
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
SessionManager::start();

$pdo = Database::connection();
$auditService = new AuditService($pdo);
$authService = new AuthService($pdo, $auditService);
$mailerService = new MailerService($pdo);
$registrationService = new RegistrationService(
    $pdo,
    $auditService,
    $authService,
    $mailerService
);
$dashboardService = new DashboardService($pdo);
$notificationService = new NotificationService($pdo);
$ticketService = new TicketService($pdo, $auditService, $mailerService);
$ticketDetailService = new TicketDetailService($pdo, $auditService, $mailerService);
$quotationService = new QuotationService($pdo, $auditService, $mailerService);
$authorizationRequestService = new AuthorizationRequestService($pdo, $auditService, $mailerService);
$authorizationDecisionService = new AuthorizationDecisionService($pdo, $auditService, $mailerService);
$supervisorService = new SupervisorService($pdo);

set_exception_handler(static function (Throwable $exception) use ($debug): void {
    if ($exception instanceof HttpException) {
        Http::json([
            'ok' => false,
            'error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ],
        ], $exception->status);
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
});

return [
    'pdo' => $pdo,
    'audit' => $auditService,
    'auth' => $authService,
    'mailer' => $mailerService,
    'registration' => $registrationService,
    'dashboard' => $dashboardService,
    'notifications' => $notificationService,
    'tickets' => $ticketService,
    'ticket_detail' => $ticketDetailService,
    'quotations' => $quotationService,
    'authorization_requests' => $authorizationRequestService,
    'authorization_decisions' => $authorizationDecisionService,
    'supervisor' => $supervisorService,
];
