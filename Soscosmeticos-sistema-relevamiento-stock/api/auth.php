<?php
/**
 * API para Gestión de Autenticación y Sesión
 */
require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? null);

if (!$action) {
    $payload = getJsonInput();
    $action = $payload['action'] ?? 'check';
}

switch ($action) {
    case 'check':
        if (!empty($_SESSION['logged_in']) && !empty($_SESSION['usuario'])) {
            sendJsonResponse([
                'authenticated' => true,
                'usuario' => $_SESSION['usuario'],
                'role' => $_SESSION['role'] ?? 'vendedor'
            ]);
        } else {
            sendJsonResponse([
                'authenticated' => false,
                'usuario' => null,
                'role' => null
            ]);
        }
        break;

    case 'logout':
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
        sendJsonResponse(['ok' => true, 'message' => 'logged_out']);
        break;

    default:
        sendJsonError('invalid_action', 400);
        break;
}
