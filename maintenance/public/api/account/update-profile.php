<?php

declare(strict_types=1);

use App\Core\Http;
use App\Security\Csrf;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';

Http::requireMethod('POST');
$input = Http::jsonInput();
Csrf::validate($input);

$result = $services['account']->updateProfile($input);
$emailSent = null;

if ((bool) ($result['changed'] ?? false)) {
    $profile = $result['profile'];
    $emailSent = true;
    try {
        $services['mailer']->sendProfileChangedNotification(
            (int) $profile['id'],
            (string) $profile['email'],
            (string) $profile['full_name']
        );
    } catch (Throwable) {
        $emailSent = false;
        $services['audit']->safeRecord(
            'account.profile.change_notification_failed',
            'user',
            (int) $profile['id'],
            (int) $profile['id'],
            ['email' => (string) $profile['email']]
        );
    }
}

Http::json([
    'ok' => true,
    'data' => $result + [
        'email_notification_sent' => $emailSent,
    ],
]);
