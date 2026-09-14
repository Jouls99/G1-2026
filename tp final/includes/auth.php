<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Comprueba si hay una sesión de usuario activa.
 */
function isLoggedIn(): bool
{
    return !empty($_SESSION['user']) && is_array($_SESSION['user']);
}

/**
 * Obtiene el usuario autenticado actual o null.
 */
function getCurrentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * Protege una página requiriendo que el usuario esté autenticado.
 * Si no lo está, redirige a la pantalla de login.
 */
function requireAuth(string $redirectUrl = 'registroinicio.php'): void
{
    if (!isLoggedIn()) {
        header('Location: ' . $redirectUrl);
        exit;
    }
}

/**
 * Si el usuario ya está autenticado, lo redirige al panel principal.
 */
function redirectIfLoggedIn(string $targetUrl = 'prueba2.php'): void
{
    if (isLoggedIn()) {
        header('Location: ' . $targetUrl);
        exit;
    }
}

/**
 * Comprueba si el usuario autenticado tiene rol de administrador.
 */
function isAdmin(): bool
{
    if (!isLoggedIn()) {
        return false;
    }
    $role = strtolower((string)($_SESSION['user']['role'] ?? $_SESSION['user']['rol'] ?? ''));
    return in_array($role, ['administrador', 'admin'], true);
}

/**
 * Comprueba si el usuario actual tiene un rol específico.
 */
function hasRole(string $role): bool
{
    if (!isLoggedIn()) {
        return false;
    }
    $currentRole = strtolower((string)($_SESSION['user']['role'] ?? $_SESSION['user']['rol'] ?? ''));
    return $currentRole === strtolower($role);
}

/**
 * Protege una página requiriendo que el usuario sea Administrador.
 * Si no está autenticado o no es admin, redirige al panel de ventas o login.
 */
function requireAdmin(string $redirectUrl = 'prueba2.php'): void
{
    if (!isLoggedIn()) {
        header('Location: registroinicio.php');
        exit;
    }

    if (!isAdmin()) {
        header('Location: ' . $redirectUrl . '?error=unauthorized');
        exit;
    }
}

/**
 * Cierra la sesión activa y limpia los datos.
 */
function logoutUser(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

