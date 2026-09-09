<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => is_file($path) ? (string) file_get_contents($path) : '';

$mailer = $read($root . '/app/Services/MailerService.php');
$auth = $read($root . '/app/Services/AuthService.php');
$registration = $read($root . '/app/Services/RegistrationService.php');
$invitations = $read($root . '/app/Services/UserInvitationService.php');
$userAdmin = $read($root . '/app/Services/UserAdminService.php');
$changeApi = $read($root . '/public/api/auth/change-password.php');
$profileApi = $read($root . '/public/api/account/update-profile.php');
$userMenu = $read($root . '/public/assets/js/components/userMenu.js');

$blockingMailPattern = <<<'PHP'
assertRegistrationRateLimit(Http::clientIp());
        $this->mailer->assertReady();
PHP;

$checks = [
    'profile_notification_mailer' => str_contains($mailer, 'sendProfileChangedNotification('),
    'password_notification_mailer' => str_contains($mailer, 'sendPasswordChangedNotification('),
    'mailer_configuration_failures_are_logged' => str_contains($mailer, "try {\n            \$this->assertReady();\n            \$transport = strtolower"),
    'password_change_returns_identity' => str_contains($auth, 'changePassword(string $currentPassword, string $newPassword, string $confirmation): array'),
    'register_business_not_blocked_by_mail_config' => !str_contains($registration, $blockingMailPattern),
    'verification_delivery_finalization' => str_contains($registration, 'finalizeVerificationTokenDelivery('),
    'invitation_delivery_finalization' => str_contains($invitations, 'user.invitation.delivery_finalize_failed'),
    'invitation_directory_prefers_usable_token' => str_contains($userAdmin, 'uit2.expires_at > UTC_TIMESTAMP()'),
    'profile_email_best_effort' => str_contains($profileApi, 'account.profile.change_notification_failed'),
    'password_email_best_effort' => str_contains($changeApi, 'auth.password.change_notification_failed'),
    'password_visibility_component' => is_file($root . '/public/assets/js/components/passwordVisibility.js'),
    'password_visibility_styles' => is_file($root . '/public/assets/css/password-visibility.css'),
    'authenticated_profile_visibility_hook' => str_contains($userMenu, 'setupPasswordVisibility();'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));

echo json_encode([
    'ok' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($failed === [] ? 0 : 1);
