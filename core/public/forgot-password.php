<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/bootstrap/app.php';

header('Cache-Control: no-store, private');

$services['webAuth']->redirectIfAuthenticated('/account');

require __DIR__ . '/forgot-password.html';