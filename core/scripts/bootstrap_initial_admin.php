<?php

declare (strict_types = 1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solamente puede ejecutarse desde CLI.\n");
}

$coreRoot        = dirname(__DIR__);
$projectRoot     = dirname($coreRoot);
$maintenanceRoot = $projectRoot . '/maintenance';

function loadEnvironment(string $path): array
{
    if (! is_file($path)) {
        throw new RuntimeException("No existe el archivo de entorno: {$path}");
    }

    $values = [];
    $lines  = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        throw new RuntimeException("No fue posible leer: {$path}");
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $separator = strpos($line, '=');

        if ($separator === false) {
            continue;
        }

        $key   = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $values[$key] = $value;
    }

    return $values;
}

function connectDatabase(array $env): PDO
{
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $required) {
        if (! array_key_exists($required, $env)) {
            throw new RuntimeException("Falta {$required}.");
        }
    }

    $host     = $env['DB_HOST'];
    $port     = (int) ($env['DB_PORT'] ?? 3306);
    $database = $env['DB_NAME'];
    $charset  = $env['DB_CHARSET'] ?? 'utf8mb4';

    return new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
        $env['DB_USER'],
        $env['DB_PASS'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
}

try {
    $maintenanceEnv = loadEnvironment($maintenanceRoot . '/.env');
    $coreEnv        = loadEnvironment($coreRoot . '/.env');

    $maintenance = connectDatabase($maintenanceEnv);
    $core        = connectDatabase($coreEnv);

    // -----------------------------------------------------
    // 1. Read-only source validation
    // -----------------------------------------------------

    $source = $maintenance->prepare(
        'SELECT
            u.id,
            u.first_name,
            u.last_name,
            u.email,
            u.password_hash,
            u.status,
            u.must_change_password,
            u.failed_login_attempts,
            u.locked_until,
            u.last_login_at,
            u.email_verified_at,
            u.created_at,
            u.deactivated_at,
            r.code AS role_code
         FROM users u
         INNER JOIN user_roles ur ON ur.user_id = u.id
         INNER JOIN roles r ON r.id = ur.role_id
         WHERE u.id = :id
         LIMIT 1'
    );

    $source->execute(['id' => 1]);
    $user = $source->fetch();

    if (! is_array($user)) {
        throw new RuntimeException('No existe el usuario Maintenance id=1.');
    }

    if ((string) $user['status'] !== 'active') {
        throw new RuntimeException('El usuario origen no está activo.');
    }

    if ((string) $user['role_code'] !== 'administrator') {
        throw new RuntimeException('El usuario origen no tiene rol administrator.');
    }

    if (! is_string($user['password_hash']) || $user['password_hash'] === '') {
        throw new RuntimeException('El usuario origen no tiene password_hash válido.');
    }

    echo "Origen validado:\n";
    echo "  ID: {$user['id']}\n";
    echo "  Email: {$user['email']}\n";
    echo "  Estado: {$user['status']}\n";
    echo "  Rol: {$user['role_code']}\n\n";

    // -----------------------------------------------------
    // 2. Core preflight
    // -----------------------------------------------------

    $existing = $core->prepare(
        'SELECT id
         FROM users
         WHERE id = :id OR email = :email
         LIMIT 1'
    );

    $existing->execute([
        'id'    => 1,
        'email' => $user['email'],
    ]);

    if ($existing->fetch() !== false) {
        throw new RuntimeException(
            'Core ya contiene el usuario id=1 o el mismo correo. No se modificó nada.'
        );
    }

    // -----------------------------------------------------
    // 3. Resolve Core application/role identifiers
    // -----------------------------------------------------

    $roleLookup = $core->prepare(
        'SELECT
            a.id AS application_id,
            a.code AS application_code,
            r.id AS role_id,
            r.code AS role_code
         FROM applications a
         INNER JOIN application_roles r
            ON r.application_id = a.id
         WHERE
            (a.code = :core_app AND r.code = :core_role)
            OR
            (a.code = :maintenance_app AND r.code = :maintenance_role)'
    );

    $roleLookup->execute([
        'core_app'         => 'core',
        'core_role'        => 'system_administrator',
        'maintenance_app'  => 'maintenance',
        'maintenance_role' => 'administrator',
    ]);

    $roles = [];

    foreach ($roleLookup->fetchAll() as $row) {
        $roles[$row['application_code']] = $row;
    }

    if (! isset($roles['core'], $roles['maintenance'])) {
        throw new RuntimeException(
            'No fue posible resolver los roles Core requeridos.'
        );
    }

    // -----------------------------------------------------
    // 4. Core write transaction
    // -----------------------------------------------------

    $core->beginTransaction();

    try {
        $insertUser = $core->prepare(
            'INSERT INTO users (
                id,
                first_name,
                last_name,
                email,
                password_hash,
                status,
                must_change_password,
                failed_login_attempts,
                locked_until,
                last_login_at,
                email_verified_at,
                created_at,
                deactivated_at
             ) VALUES (
                :id,
                :first_name,
                :last_name,
                :email,
                :password_hash,
                :status,
                :must_change_password,
                :failed_login_attempts,
                :locked_until,
                :last_login_at,
                :email_verified_at,
                :created_at,
                :deactivated_at
             )'
        );

        $insertUser->execute([
            'id'                    => (int) $user['id'],
            'first_name'            => $user['first_name'],
            'last_name'             => $user['last_name'],
            'email'                 => $user['email'],
            'password_hash'         => $user['password_hash'],
            'status'                => $user['status'],
            'must_change_password'  => (int) $user['must_change_password'],
            'failed_login_attempts' => (int) $user['failed_login_attempts'],
            'locked_until'          => $user['locked_until'],
            'last_login_at'         => $user['last_login_at'],
            'email_verified_at'     => $user['email_verified_at'],
            'created_at'            => $user['created_at'],
            'deactivated_at'        => $user['deactivated_at'],
        ]);

        $assignRole = $core->prepare(
            'INSERT INTO user_application_roles (
                user_id,
                application_id,
                role_id,
                assigned_by_user_id
             ) VALUES (
                :user_id,
                :application_id,
                :role_id,
                :assigned_by_user_id
             )'
        );

        foreach (['core', 'maintenance'] as $applicationCode) {
            $assignRole->execute([
                'user_id'             => 1,
                'application_id'      => (int) $roles[$applicationCode]['application_id'],
                'role_id'             => (int) $roles[$applicationCode]['role_id'],
                'assigned_by_user_id' => 1,
            ]);
        }

        $audit = $core->prepare(
            'INSERT INTO audit_log (
                actor_user_id,
                application_id,
                action_code,
                entity_type,
                entity_id,
                new_values_json
             ) VALUES (
                :actor_user_id,
                :application_id,
                :action_code,
                :entity_type,
                :entity_id,
                :new_values_json
             )'
        );

        $audit->execute([
            'actor_user_id'   => 1,
            'application_id'  => (int) $roles['core']['application_id'],
            'action_code'     => 'system.bootstrap.initial_admin',
            'entity_type'     => 'user',
            'entity_id'       => 1,
            'new_values_json' => json_encode(
                [
                    'source'           => 'maintenance_v1',
                    'core_role'        => 'system_administrator',
                    'maintenance_role' => 'administrator',
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
        ]);

        $core->commit();
    } catch (Throwable $exception) {
        if ($core->inTransaction()) {
            $core->rollBack();
        }

        throw $exception;
    }

    // -----------------------------------------------------
    // 5. Post-write validation
    // -----------------------------------------------------

    $target = $core->prepare(
        'SELECT id, email, password_hash, status
         FROM users
         WHERE id = :id
         LIMIT 1'
    );

    $target->execute(['id' => 1]);
    $coreUser = $target->fetch();

    if (! is_array($coreUser)) {
        throw new RuntimeException('El usuario no apareció en Core.');
    }

    $hashMatches = hash_equals(
        (string) $user['password_hash'],
        (string) $coreUser['password_hash']
    );

    echo "Bootstrap completado.\n";
    echo "  Core user ID: {$coreUser['id']}\n";
    echo "  Email: {$coreUser['email']}\n";
    echo "  Estado: {$coreUser['status']}\n";
    echo '  Password hash: ' . ($hashMatches ? 'MATCH' : 'MISMATCH') . "\n";
    echo "  Core role: system_administrator\n";
    echo "  Maintenance role: administrator\n";

    if (! $hashMatches) {
        exit(2);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "ERROR: {$exception->getMessage()}\n");
    exit(1);
}