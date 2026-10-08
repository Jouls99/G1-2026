<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

// Atiende las preflight de CORS antes de validar el método de la operación.
handleOptions();

if (requestMethod() !== 'POST') {
    sendJson(['ok' => false, 'message' => 'Método no permitido.'], 405);
}

// Solo el usuario autenticado puede renovar el registro de actividad de su sesión.
requireApiAuth();

$user = getApiUser();
if (!is_array($user)) {
    sendJson(['ok' => false, 'message' => 'La sesión no es válida.'], 401);
}

// Confirma que la sesión existe y responde con éxito para el latido periódico del pie de página.
sendJson(['ok' => true]);
