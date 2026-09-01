<?php
/**
 * API para Gestión de Inventario
 */
require_once __DIR__ . '/config.php';

// Exigir autenticación: solo usuarios registrados pueden ver o modificar el inventario
require_api_auth();

$inventarioFile = getDataFilePath('inventario.json');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $inventario = readJsonFile($inventarioFile, []);
    sendJsonResponse($inventario);
}

if ($method === 'PUT' || $method === 'POST') {
    $payload = getJsonInput();
    
    if (!is_array($payload)) {
        sendJsonError('invalid_payload', 400);
    }
    
    // Normalizar datos de inventario
    $sanitized = [];
    foreach ($payload as $item) {
        if (!is_array($item)) continue;
        
        $precio = floatval($item['precio'] ?? 0);
        $cantidad = intval($item['cantidad'] ?? ($item['stock'] ?? 0));
        
        $sanitized[] = [
            'categoria' => trim($item['categoria'] ?? 'Sin categoría'),
            'nombre' => trim($item['nombre'] ?? ''),
            'codigo' => trim($item['codigo'] ?? ''),
            'precio' => $precio,
            'cantidad' => $cantidad,
            'total' => isset($item['total']) ? floatval($item['total']) : ($precio * $cantidad),
            'subcategoria' => isset($item['subcategoria']) ? trim($item['subcategoria']) : null
        ];
    }
    
    if (!writeJsonFile($inventarioFile, $sanitized)) {
        sendJsonError('save_error', 500);
    }
    
    sendJsonResponse([
        'ok' => true,
        'count' => count($sanitized)
    ]);
}

sendJsonError('method_not_allowed', 405);
