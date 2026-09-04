<?php

declare(strict_types=1);

use App\Core\Http;
use App\Security\Csrf;
use App\Services\AuthorizationService;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';

Http::requireMethod('POST');
$input = Http::jsonInput();
Csrf::validate($input);

$user = $services['auth']->currentUser();
AuthorizationService::requirePasswordChanged($user);

$result = $services['authorization_decisions']->decide($user, $input);
$detail = $services['ticket_detail']->detail($user, (int) $input['ticket_id']);

Http::json([
    'ok' => true,
    'data' => $detail,
    'meta' => $result,
]);
