<?php

declare(strict_types=1);

use App\Core\Http;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';

Http::requireMethod('GET');
$context = $services['registration']->context(
    isset($_GET['location']) ? (string) $_GET['location'] : null
);

Http::json([
    'ok' => true,
    'data' => $context,
]);
