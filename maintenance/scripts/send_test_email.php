<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/bootstrap/app.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse desde CLI.\n");
    exit(1);
}

$email = strtolower(trim((string) ($argv[1] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php scripts/send_test_email.php usuario@dominio.com\n");
    exit(1);
}

try {
    $services['mailer']->sendTestEmail($email);
    fwrite(STDOUT, "Correo de prueba enviado o registrado correctamente a {$email}.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "ERROR: {$exception->getMessage()}\n");
    exit(1);
}
