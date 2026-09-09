<?php

declare(strict_types=1);

use App\Core\Http;
use App\Security\Csrf;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';

Http::requireMethod('POST');
$input = Http::jsonInput();
Csrf::validate($input);

$result = $services['auth']->changePassword(
    (string) ($input['current_password'] ?? ''),
    (string) ($input['new_password'] ?? ''),
    (string) ($input['new_password_confirmation'] ?? '')
);

$emailSent = true;
try {
    $services['mailer']->sendPasswordChangedNotification(
        (int) $result['user_id'],
        (string) $result['email'],
        (string) $result['full_name']
    );
} catch (Throwable) {
    $emailSent = false;
    $services['audit']->safeRecord(
        'auth.password.change_notification_failed',
        'user',
        (int) $result['user_id'],
        (int) $result['user_id'],
        ['email' => (string) $result['email']]
    );
}

Http::json([
    'ok' => true,
    'data' => [
        'password_changed' => true,
        'email_notification_sent' => $emailSent,
    ],
]);
