<?php

declare(strict_types=1);

use App\Config\Env;

$services = require dirname(__DIR__) . '/bootstrap/app.php';
$pdo = $services['pdo'];

$errors = [];
$warnings = [];

echo "VALIDACIÓN DEL REGISTRO PÚBLICO\n";
echo "================================\n";

echo 'PHP: ' . PHP_VERSION . PHP_EOL;
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    $errors[] = 'Se requiere PHP 8.1 o superior.';
}

foreach (['pdo_mysql', 'filter', 'hash', 'openssl'] as $extension) {
    $loaded = extension_loaded($extension);
    echo sprintf("Extensión %-12s: %s\n", $extension, $loaded ? 'OK' : 'FALTA');
    if (!$loaded) {
        $errors[] = "Falta la extensión {$extension}.";
    }
}

$tables = ['users', 'roles', 'user_roles', 'locations', 'email_verification_tokens', 'email_log', 'audit_log', 'user_sessions'];
foreach ($tables as $table) {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table'
    );
    $statement->execute(['table' => $table]);
    $exists = (int) $statement->fetchColumn() === 1;
    echo sprintf("Tabla %-27s: %s\n", $table, $exists ? 'OK' : 'FALTA');
    if (!$exists) {
        $errors[] = "Falta la tabla {$table}.";
    }
}

$role = $pdo->query("SELECT id, is_active FROM roles WHERE code = 'reporter' LIMIT 1")->fetch();
if (!is_array($role) || (int) $role['is_active'] !== 1) {
    $errors[] = 'El rol reporter no existe o está inactivo.';
    echo "Rol reporter: FALTA\n";
} else {
    echo "Rol reporter: OK\n";
}

$locationCount = (int) $pdo->query('SELECT COUNT(*) FROM locations WHERE is_active = 1')->fetchColumn();
echo "Ubicaciones activas: {$locationCount}\n";
if ($locationCount < 1) {
    $errors[] = 'No hay ubicaciones activas.';
}

$transport = strtolower(Env::get('MAIL_TRANSPORT', 'smtp'));
echo "Transporte de correo: {$transport}\n";

if ($transport === 'smtp') {
    if (!is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
        $errors[] = 'No existe vendor/autoload.php; ejecuta composer install.';
    }

    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        $errors[] = 'PHPMailer no está disponible.';
    }

    foreach (['SMTP_HOST', 'SMTP_FROM_ADDRESS'] as $key) {
        try {
            Env::get($key);
        } catch (Throwable) {
            $errors[] = "Falta {$key} en .env.";
        }
    }
}

if ($transport === 'log') {
    $warnings[] = 'MAIL_TRANSPORT=log solo debe utilizarse para pruebas; el token quedará en storage/logs/mail.log.';
}

if ($warnings !== []) {
    echo "\nADVERTENCIAS\n";
    foreach ($warnings as $warning) {
        echo "- {$warning}\n";
    }
}

if ($errors !== []) {
    echo "\nERRORES\n";
    foreach ($errors as $error) {
        echo "- {$error}\n";
    }
    exit(1);
}

echo "\nVALIDACIÓN CORRECTA\n";
