<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/bootstrap/app.php';
$pdo = $services['pdo'];

$errors = [];

echo "VALIDACIÓN DEL FORMULARIO DE REPORTES\n";
echo "=====================================\n";

$requiredExtensions = ['pdo_mysql', 'fileinfo', 'hash'];
foreach ($requiredExtensions as $extension) {
    $ok = extension_loaded($extension);
    printf("Extensión %-12s: %s\n", $extension, $ok ? 'OK' : 'ERROR');
    if (!$ok) {
        $errors[] = "Falta la extensión {$extension}.";
    }
}

$columns = [
    ['tickets', 'specific_location'],
    ['locations', 'default_supervisor_user_id'],
];

foreach ($columns as [$table, $column]) {
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name'
    );
    $statement->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);
    $ok = (int) $statement->fetchColumn() === 1;
    printf("Columna %-39s: %s\n", "{$table}.{$column}", $ok ? 'OK' : 'ERROR');
    if (!$ok) {
        $errors[] = "No existe {$table}.{$column}.";
    }
}

$tables = [
    'tickets',
    'ticket_status_history',
    'ticket_assignment_history',
    'ticket_attachments',
    'notifications',
    'email_log',
    'audit_log',
    'folio_sequences',
];

foreach ($tables as $table) {
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :table_name'
    );
    $statement->execute(['table_name' => $table]);
    $ok = (int) $statement->fetchColumn() === 1;
    printf("Tabla %-41s: %s\n", $table, $ok ? 'OK' : 'ERROR');
    if (!$ok) {
        $errors[] = "Falta la tabla {$table}.";
    }
}

$permissionStatement = $pdo->query(
    "SELECT COUNT(*) FROM permissions WHERE code = 'ticket.create'"
);
$permissionOk = (int) $permissionStatement->fetchColumn() === 1;
printf("Permiso ticket.create                     : %s\n", $permissionOk ? 'OK' : 'ERROR');
if (!$permissionOk) {
    $errors[] = 'Falta el permiso ticket.create.';
}

$catalogStatement = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM locations WHERE is_active = 1) AS locations_total,
        (SELECT COUNT(*) FROM locations l INNER JOIN users u ON u.id = l.default_supervisor_user_id WHERE l.is_active = 1 AND u.status = 'active') AS locations_with_supervisor,
        (SELECT COUNT(*) FROM ticket_priorities WHERE is_active = 1) AS priorities_total,
        (SELECT COUNT(*) FROM ticket_statuses WHERE code = 'new' AND is_active = 1) AS new_status_total"
);
$catalog = $catalogStatement->fetch();

printf("Ubicaciones activas                       : %d\n", (int) $catalog['locations_total']);
printf("Ubicaciones con supervisor activo         : %d\n", (int) $catalog['locations_with_supervisor']);
printf("Prioridades activas                       : %d\n", (int) $catalog['priorities_total']);
printf("Estado inicial new                        : %s\n", (int) $catalog['new_status_total'] === 1 ? 'OK' : 'ERROR');

if ((int) $catalog['locations_total'] < 1) {
    $errors[] = 'No existen ubicaciones activas.';
}
if ((int) $catalog['locations_with_supervisor'] !== (int) $catalog['locations_total']) {
    $errors[] = 'No todas las ubicaciones activas tienen supervisor activo.';
}
if ((int) $catalog['priorities_total'] < 1) {
    $errors[] = 'No existen prioridades activas.';
}
if ((int) $catalog['new_status_total'] !== 1) {
    $errors[] = 'El estado new no está disponible.';
}

$migrations = $pdo->query(
    "SELECT version FROM schema_migrations WHERE version IN ('09', '10') ORDER BY version"
)->fetchAll(PDO::FETCH_COLUMN);
echo 'Migraciones 09 y 10                      : ' . ($migrations === ['09', '10'] ? 'OK' : 'ERROR') . PHP_EOL;
if ($migrations !== ['09', '10']) {
    $errors[] = 'Las migraciones 09 y 10 no están registradas.';
}

$uploadPath = dirname(__DIR__) . '/storage/uploads';
if (!is_dir($uploadPath) && !mkdir($uploadPath, 0770, true) && !is_dir($uploadPath)) {
    $errors[] = 'No fue posible crear storage/uploads.';
}
$writable = is_dir($uploadPath) && is_writable($uploadPath);
printf("storage/uploads escribible                : %s\n", $writable ? 'OK' : 'ERROR');
if (!$writable) {
    $errors[] = 'storage/uploads no tiene permisos de escritura.';
}

echo 'upload_max_filesize                       : ' . ini_get('upload_max_filesize') . PHP_EOL;
echo 'post_max_size                             : ' . ini_get('post_max_size') . PHP_EOL;
echo 'max_file_uploads                          : ' . ini_get('max_file_uploads') . PHP_EOL;

if ($errors !== []) {
    echo "\nVALIDACIÓN CON ERRORES\n";
    foreach ($errors as $error) {
        echo "- {$error}\n";
    }
    exit(1);
}

echo "\nVALIDACIÓN CORRECTA\n";
