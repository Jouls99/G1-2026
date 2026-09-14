<?php
/**
 * Manejo de sesiones de usuario en PHP
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function is_logged_in() {
    return !empty($_SESSION['logged_in']) && !empty($_SESSION['usuario']);
}

function current_user() {
    return is_logged_in() ? $_SESSION['usuario'] : null;
}

function current_role() {
    return is_logged_in() ? ($_SESSION['role'] ?? 'vendedor') : null;
}

function require_auth($redirectUrl = 'login.php') {
    if (!is_logged_in()) {
        header("Location: $redirectUrl");
        exit;
    }
}
