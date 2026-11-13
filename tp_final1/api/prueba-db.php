<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/conexion.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDBConnection();
    if ($pdo === null) {
        throw new RuntimeException('No se pudo establecer la conexión con la base de datos.');
    }
    $stmt = $pdo->query("SELECT 1 AS conectado");
    $resultado = $stmt->fetch();

    echo json_encode([
        'ok' => true,
        'mensaje' => 'Conexión con MySQL exitosa',
        'database' => defined('DB_NAME') ? DB_NAME : 'sos_cosmeticos',
        'resultado' => $resultado
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'mensaje' => 'Error al conectar con MySQL: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
