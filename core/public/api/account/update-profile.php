<?php

declare(strict_types=1);

use App\Core\Http;
use EUTools\Shared\Security\Csrf;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';

Http::requireMethod('POST');

$input = Http::jsonInput(8192);

Csrf::validate($input);

$result = $services['account']->updateProfile($input);

Http::json([
    'ok' => true,
    'data' => $result,
]);
