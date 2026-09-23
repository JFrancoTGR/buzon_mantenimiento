<?php

declare(strict_types=1);

use App\Core\Http;
use EUTools\Shared\Security\Csrf;
use App\Services\AuthorizationService;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';
Http::requireMethod('POST');
$user = $services['maintenance_context']->currentUser();
AuthorizationService::requirePasswordChanged($user);
Csrf::validate();
$input = Http::jsonInput(4096);
$notificationId = filter_var($input['notification_id'] ?? null, FILTER_VALIDATE_INT);
if (!is_int($notificationId) || $notificationId < 1) {
    throw new App\Exceptions\HttpException(404, 'notification_not_found', 'La notificación no existe.');
}
Http::json(['ok' => true, 'data' => $services['notifications']->markRead($user, $notificationId)]);
