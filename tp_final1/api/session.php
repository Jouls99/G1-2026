<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

handleOptions();

if (requestMethod() !== 'POST') {
    sendJson(['ok' => false, 'message' => 'Método no permitido.'], 405);
}

requireApiAuth();

$user = getApiUser();
if (!is_array($user)) {
    sendJson(['ok' => false, 'message' => 'La sesión no es válida.'], 401);
}

sendJson(['ok' => true]);
