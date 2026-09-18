<?php

declare(strict_types=1);

$app = require __DIR__ . '/../../bootstrap/app.php';

/** @var PDO $pdo */
$pdo = $app['pdo'];

$database = $pdo->query('SELECT DATABASE()')->fetchColumn();

\App\Core\Http::json([
    'ok' => true,
    'service' => 'eu-tools-core',
    'database' => $database,
]);