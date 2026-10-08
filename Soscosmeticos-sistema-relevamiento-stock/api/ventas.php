<?php
/**
 * API para Gestión de Ventas
 * Endpoint de consulta, registro, reemplazo y eliminación de ventas del historial.
 */
require_once __DIR__ . '/config.php';

// Exigir autenticación: solo usuarios registrados pueden ver o registrar ventas
require_api_auth();

$ventasFile = getDataFilePath('ventas.json');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Consultar el historial para el dashboard de informes y otras vistas.
if ($method === 'GET') {
    $ventas = readJsonFile($ventasFile, []);
    sendJsonResponse($ventas);
}

// Agregar una venta validada y completar identificador, fecha y montos compatibles con el historial.
if ($method === 'POST') {
    $payload = getJsonInput();
    
    if (!$payload || !isset($payload['productos']) || !is_array($payload['productos']) || count($payload['productos']) === 0) {
        sendJsonError('invalid_payload', 400);
    }
    
    $ventas = readJsonFile($ventasFile, []);
    
    $ventaId = isset($payload['id']) && !empty($payload['id']) ? strval($payload['id']) : strval(round(microtime(true) * 1000));
    $fecha = isset($payload['fecha']) && !empty($payload['fecha']) ? $payload['fecha'] : date('c');
    
    $venta = [
        'id' => $ventaId,
        'productos' => $payload['productos'],
        'total' => floatval($payload['total'] ?? 0),
        'dinero' => floatval($payload['dinero'] ?? ($payload['total'] ?? 0)),
        'totalInventario' => isset($payload['totalInventario']) ? floatval($payload['totalInventario']) : null,
        'fecha' => $fecha
    ];
    
    $ventas[] = $venta;
    
    if (!writeJsonFile($ventasFile, $ventas)) {
        sendJsonError('save_error', 500);
    }
    
    sendJsonResponse([
        'ok' => true,
        'venta' => $venta
    ]);
}

// Reemplazar el historial completo; se usa al editar una venta desde el informe.
if ($method === 'PUT') {
    $payload = getJsonInput();
    
    if (!is_array($payload)) {
        sendJsonError('invalid_payload', 400);
    }
    
    if (!writeJsonFile($ventasFile, $payload)) {
        sendJsonError('save_error', 500);
    }
    
    sendJsonResponse([
        'ok' => true,
        'count' => count($payload)
    ]);
}

// Eliminar por identificador para clientes que solicitan una baja puntual de la venta.
if ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) {
        $payload = getJsonInput();
        $id = $payload['id'] ?? null;
    }
    
    if (!$id) {
        sendJsonError('missing_id', 400);
    }
    
    $ventas = readJsonFile($ventasFile, []);
    $initialCount = count($ventas);
    
    $ventas = array_values(array_filter($ventas, function($v) use ($id) {
        return strval($v['id'] ?? '') !== strval($id);
    }));
    
    if (count($ventas) === $initialCount) {
        sendJsonError('sale_not_found', 404);
    }
    
    if (!writeJsonFile($ventasFile, $ventas)) {
        sendJsonError('save_error', 500);
    }
    
    sendJsonResponse(['ok' => true, 'deleted_id' => $id]);
}

sendJsonError('method_not_allowed', 405);
