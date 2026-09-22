<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/bootstrap/app.php';

header('Cache-Control: no-store, private');

$services['webAuth']->requireUser('/login', true);

require __DIR__ . '/change-password.html';