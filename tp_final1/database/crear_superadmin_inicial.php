<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$username = trim((string)($argv[1] ?? ''));
if ($argc !== 2 || $username === '') {
    fwrite(STDERR, "Uso: php database/crear_superadmin_inicial.php <nombre_de_usuario>\n");
    exit(2);
}

require_once __DIR__ . '/conexion.php';

$pdo = getDBConnection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "No se pudo conectar a la base de datos.\n");
    exit(1);
}

try {
    $password = createInitialSuperAdmin($pdo, $username);
    fwrite(STDOUT, "Super Administrador creado.\n");
    fwrite(STDOUT, "Usuario: {$username}\n");
    fwrite(STDOUT, "Contraseña (se muestra una sola vez): {$password}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "No se pudo crear el Super Administrador: {$e->getMessage()}\n");
    exit(1);
}
