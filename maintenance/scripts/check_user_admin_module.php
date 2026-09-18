<?php

declare(strict_types=1);

use App\Config\Env;

$services = require dirname(__DIR__) . '/bootstrap/app.php';
$pdo = $services['pdo'];

$checks = [];
$check = static function (string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = [$name, $ok, $detail];
};

$migration = $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = '15'")->fetchColumn();
$check('Migración 15 registrada', (int) $migration === 1);

$statusType = $pdo->query(
    "SELECT column_type FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'status'"
)->fetchColumn();
$check('users.status contiene invited', is_string($statusType) && str_contains($statusType, "'invited'"), (string) $statusType);

$passwordNullable = $pdo->query(
    "SELECT is_nullable FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'password_hash'"
)->fetchColumn();
$check('users.password_hash admite NULL', $passwordNullable === 'YES', (string) $passwordNullable);

$table = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'user_invitation_tokens'"
)->fetchColumn();
$check('user_invitation_tokens existe', (int) $table === 1);

$singleRole = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'user_roles'
       AND index_name = 'uq_user_roles_single_role'
       AND non_unique = 0"
)->fetchColumn();
$check('Restricción de un rol por usuario vigente', (int) $singleRole >= 1);

$activeWithoutPassword = $pdo->query(
    "SELECT COUNT(*) FROM users WHERE status = 'active' AND password_hash IS NULL"
)->fetchColumn();
$check('Sin usuarios activos sin contraseña', (int) $activeWithoutPassword === 0, (string) $activeWithoutPassword);

$check('Servicio user_invitations cargado', isset($services['user_invitations']));
$check('Servicio user_admin cargado', isset($services['user_admin']));
$check('Mailer soporta invitaciones', method_exists($services['mailer'], 'sendUserInvitationEmail'));

$ttl = Env::int('USER_INVITATION_TTL_HOURS', 72);
$check('TTL de invitación válido', $ttl >= 1 && $ttl <= 720, $ttl . ' horas');

$failed = 0;
foreach ($checks as [$name, $ok, $detail]) {
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $name;
    if ($detail !== '') {
        echo ' — ' . $detail;
    }
    echo PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

if ($failed > 0) {
    echo PHP_EOL . "Resultado: {$failed} validación(es) fallaron." . PHP_EOL;
    exit(1);
}

echo PHP_EOL . 'Resultado: módulo Usuarios preparado correctamente.' . PHP_EOL;
