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
function redirectIfLoggedIn(string $targetUrl = 'venta.php'): void
{
    if (isLoggedIn()) {
        header('Location: ' . $targetUrl);
        exit;
    }
}

/**
 * Comprueba si el usuario autenticado tiene rol de Super Administrador.
 */
function isSuperAdmin(): bool
{
    if (!isLoggedIn()) {
        return false;
    }
    $role = strtolower(trim((string)($_SESSION['user']['role'] ?? $_SESSION['user']['rol'] ?? '')));
    return in_array($role, ['superadmin', 'super administrador', 'super_admin', 'super-admin'], true);
}

/**
 * Comprueba si el usuario autenticado tiene rol de Administrador o superior.
 */
function isAdmin(): bool
{
    if (!isLoggedIn()) {
        return false;
    }
    if (isSuperAdmin()) {
        return true;
    }
    $role = strtolower(trim((string)($_SESSION['user']['role'] ?? $_SESSION['user']['rol'] ?? '')));
    return in_array($role, ['administrador', 'admin'], true);
}

/** Comprueba un permiso delegable del vendedor desde la fuente persistente. */
function hasUserPermission(string $permission): bool
{
    $allowedPermissions = ['puede_registrar_stock', 'puede_modificar_informes'];
    if (!isLoggedIn() || !in_array($permission, $allowedPermissions, true)) {
        return false;
    }

    $user = getCurrentUser();
    require_once dirname(__DIR__) . '/database/conexion.php';
    $db = getDBConnection();
    if ($db === null) {
        return false;
    }

    $stmt = $db->prepare("SELECT `{$permission}` FROM `usuario` WHERE `id_usuario` = :id OR LOWER(`nombre`) = LOWER(:nombre) LIMIT 1");
    $stmt->execute([
        ':id' => (int)($user['id'] ?? 0),
        ':nombre' => (string)($user['usuario'] ?? '')
    ]);
    $value = $stmt->fetchColumn();
    return $value !== false && (bool)$value;
}

function canRegisterStock(): bool
{
    return isAdmin() || hasUserPermission('puede_registrar_stock');
}

function canModifyReports(): bool
{
    return isAdmin() || hasUserPermission('puede_modificar_informes');
}

/**
 * Comprueba si el usuario actual tiene un rol específico.
 */
function hasRole(string $role): bool
{
    if (!isLoggedIn()) {
        return false;
    }
    $currentRole = strtolower(trim((string)($_SESSION['user']['role'] ?? $_SESSION['user']['rol'] ?? '')));
    $checkRole = strtolower(trim($role));
    if ($checkRole === 'superadmin' || $checkRole === 'super administrador') {
        return isSuperAdmin();
    }
    return $currentRole === $checkRole;
}

/**
 * Protege una página requiriendo que el usuario sea Administrador (o Super Admin).
 * Si no está autenticado o no es admin, redirige al panel de ventas o login.
 */
function requireAdmin(string $redirectUrl = 'venta.php'): void
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
 * Protege una página requiriendo que el usuario sea EXCLUSIVAMENTE Super Administrador.
 * Si no lo es, deniega el acceso y redirige a la vista principal.
 */
function requireSuperAdmin(string $redirectUrl = 'venta.php'): void
{
    if (!isLoggedIn()) {
        header('Location: registroinicio.php');
        exit;
    }

    if (!isSuperAdmin()) {
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
