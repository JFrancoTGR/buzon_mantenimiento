<?php

declare(strict_types=1);

use App\Core\Http;
use App\Security\Csrf;
use App\Services\AuthorizationService;

$services = require dirname(__DIR__, 4) . '/bootstrap/app.php';

Http::requireMethod('POST');
Csrf::validate($_POST);

$user = $services['maintenance_context']->currentUser();
AuthorizationService::requirePasswordChanged($user);
AuthorizationService::requirePermission($user, 'ticket.upload.quotation');

$result = $services['quotations']->create($user, $_POST, $_FILES);
$detail = $services['ticket_detail']->detail($user, (int) $_POST['ticket_id']);

Http::json([
    'ok' => true,
    'data' => $detail,
    'meta' => $result,
], 201);
