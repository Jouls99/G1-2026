<?php
/**
 * Cierre de sesión seguro y redirección a login
 * Cierra la sesión del usuario y vuelve al formulario de acceso.
 * Se incluye desde el enlace Salir de includes/nav.php.
 */
// Iniciar la sesión solo cuando aún no hay una activa para poder limpiar sus datos.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Eliminar estado de aplicación y caducar la cookie antes de destruir la sesión del servidor.
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

session_destroy();

// Finalizar la petición en la página pública de acceso.
header('Location: login.php');
exit;
