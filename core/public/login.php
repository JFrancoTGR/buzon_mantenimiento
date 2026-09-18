<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/bootstrap/app.php';

header('Cache-Control: no-store, private');

$services['webAuth']->redirectIfAuthenticated('/');

require __DIR__ . '/login.html';