<?php
declare(strict_types=1);

require_once __DIR__ . '/session_security.php';
startSecureSession();

require_once __DIR__ . '/security_monitor.php';

// Consultas pequeñas sobre la sesión actual; las usan las vistas y las reglas de acceso.
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

/** Valida que la cuenta y su sesión persistente sigan habilitadas en MySQL. */
function validateCurrentUserSession(): bool
{
    $user = getCurrentUser();
    if (!is_array($user) || (int)($user['id'] ?? 0) <= 0 || session_id() === '') {
        return false;
    }

    require_once dirname(__DIR__) . '/database/conexion.php';
    $db = getDBConnection();
    if (!$db instanceof PDO) {
        throw new RuntimeException('No se pudo conectar a la base de datos para validar la sesión.');
    }

    $token = hash('sha256', session_id());
    $stmt = $db->prepare("
        SELECT u.`nombre`, u.`rol`, u.`estado`, s.`activa`, s.`id_usuario` AS `sesion_usuario_id`
        FROM `usuario` u
        LEFT JOIN `sesion_activa` s ON s.`token_sesion` = :token
        WHERE u.`id_usuario` = :user_id
        LIMIT 1
    ");
    $stmt->execute([
        ':token' => $token,
        ':user_id' => (int)$user['id']
    ]);
    $record = $stmt->fetch();
    $valid = is_array($record)
        && strtolower(trim((string)$record['estado'])) === 'habilitado'
        && (int)($record['activa'] ?? 0) === 1
        && (int)($record['sesion_usuario_id'] ?? 0) === (int)$user['id']
        && strcasecmp((string)$record['nombre'], (string)($user['usuario'] ?? '')) === 0
        && strcasecmp((string)$record['rol'], (string)($user['role'] ?? $user['rol'] ?? '')) === 0;

    if ($valid) {
        return true;
    }

    $close = $db->prepare("
        UPDATE `sesion_activa`
        SET `activa` = 0, `cerrada_en` = :closed_at
        WHERE `token_sesion` = :token AND `activa` = 1
    ");
    $close->execute([':closed_at' => date('Y-m-d H:i:s'), ':token' => $token]);
    logoutUser();
    return false;
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

    try {
        if (validateCurrentUserSession()) {
            return;
        }
    } catch (Throwable $e) {
        error_log('No se pudo validar la sesión de la página: ' . $e->getMessage());
        http_response_code(503);
        exit('No se pudo validar la sesión. Intentá nuevamente.');
    }

    header('Location: ' . $redirectUrl . '?error=session_expired');
    exit;
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
    $roleClean = preg_replace('/[\s_-]+/', ' ', $role);
    return in_array($role, ['superadmin', 'super admin', 'super administrador', 'super_admin', 'super-admin', 'super_administrador', 'super-administrador', 'superadministrator', 'super administrator'], true)
        || in_array($roleClean, ['superadmin', 'super admin', 'super administrador', 'super administrator'], true);
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

// Los administradores conservan acceso implícito; los vendedores dependen del permiso guardado.
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
    requireAuth();

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
    requireAuth();

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
    destroySecureSession();
}

monitorRequestForMaliciousInput();
