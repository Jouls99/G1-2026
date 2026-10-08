<?php
/**
 * Manejo de sesiones de usuario en PHP
 * Utilidades de sesión compartidas por vistas, navegación y endpoints de autenticación.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Comprobar los dos datos que identifican una sesión activa para todas las páginas protegidas.
function is_logged_in() {
    return !empty($_SESSION['logged_in']) && !empty($_SESSION['usuario']);
}

// Exponer el nombre y rol actuales a la barra de navegación sin duplicar reglas de sesión.
function current_user() {
    return is_logged_in() ? $_SESSION['usuario'] : null;
}

function current_role() {
    return is_logged_in() ? ($_SESSION['role'] ?? 'vendedor') : null;
}

// Redirigir a las vistas públicas antes de que se renderice contenido protegido.
function require_auth($redirectUrl = 'login.php') {
    if (!is_logged_in()) {
        header("Location: $redirectUrl");
        exit;
    }
}
