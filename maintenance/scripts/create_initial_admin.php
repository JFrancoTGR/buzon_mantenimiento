<?php

declare(strict_types=1);

use App\Core\Database;
use App\Exceptions\HttpException;
use App\Services\AuditService;
use App\Services\AuthService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solamente puede ejecutarse desde CLI.\n");
}

$services = require dirname(__DIR__) . '/bootstrap/app.php';
/** @var PDO $pdo */
$pdo = $services['pdo'];
/** @var AuditService $audit */
$audit = $services['audit'];

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $value = fgets(STDIN);
    return trim($value === false ? '' : $value);
}

function promptHidden(string $label): string
{
    fwrite(STDOUT, $label);

    if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
        shell_exec('stty -echo');
        $value = fgets(STDIN);
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
        return trim($value === false ? '' : $value);
    }

    fwrite(STDOUT, "\nAdvertencia: la contraseña será visible en esta terminal.\n");
    return prompt('Contraseña: ');
}

$firstName = prompt('Nombre: ');
$lastName = prompt('Apellidos: ');
$email = strtolower(prompt('Correo del administrador: '));
$password = promptHidden('Contraseña temporal: ');
$confirmation = promptHidden('Confirmar contraseña temporal: ');

if ($firstName === '' || $lastName === '') {
    fwrite(STDERR, "Nombre y apellidos son obligatorios.\n");
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "El correo no es válido.\n");
    exit(1);
}

if ($password !== $confirmation) {
    fwrite(STDERR, "Las contraseñas no coinciden.\n");
    exit(1);
}

try {
    AuthService::validatePasswordStrength($password);
} catch (HttpException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

try {
    $userId = Database::transaction(static function (PDO $pdo) use (
        $firstName,
        $lastName,
        $email,
        $password,
        $audit
    ): int {
        $roleStatement = $pdo->prepare(
            "SELECT id FROM roles WHERE code = 'administrator' AND is_active = 1 LIMIT 1"
        );
        $roleStatement->execute();
        $roleId = $roleStatement->fetchColumn();

        if ($roleId === false) {
            throw new RuntimeException('No existe el rol activo administrator. Ejecuta primero las semillas.');
        }

        $exists = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $exists->execute(['email' => $email]);
        if ($exists->fetchColumn() !== false) {
            throw new RuntimeException('Ya existe un usuario con ese correo.');
        }

        $insertUser = $pdo->prepare(
            "INSERT INTO users (
                first_name, last_name, email, password_hash, status,
                must_change_password, failed_login_attempts, email_verified_at
             ) VALUES (
                :first_name, :last_name, :email, :password_hash, 'active',
                1, 0, UTC_TIMESTAMP()
             )"
        );
        $insertUser->execute([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        $userId = (int) $pdo->lastInsertId();

        $assignRole = $pdo->prepare(
            'INSERT INTO user_roles (user_id, role_id, assigned_by_user_id)
             VALUES (:user_id, :role_id, :assigned_by_user_id)'
        );
        $assignRole->execute([
            'user_id' => $userId,
            'role_id' => (int) $roleId,
            'assigned_by_user_id' => $userId,
        ]);

        $audit->record(
            'user.create.initial_admin',
            'user',
            $userId,
            $userId,
            null,
            null,
            [
                'email' => $email,
                'role' => 'administrator',
                'must_change_password' => true,
            ]
        );

        return $userId;
    });

    fwrite(STDOUT, "\nAdministrador creado correctamente.\n");
    fwrite(STDOUT, "ID: {$userId}\n");
    fwrite(STDOUT, "Correo: {$email}\n");
    fwrite(STDOUT, "La contraseña deberá cambiarse en el primer acceso.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "No fue posible crear el administrador: {$exception->getMessage()}\n");
    exit(1);
}
