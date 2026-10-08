<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/database/conexion.php';
require_once dirname(__DIR__) . '/includes/security_monitor.php';

// Convierte los resultados de los endpoints en respuestas HTTP JSON uniformes.
/**
 * Envía una respuesta en formato JSON con los encabezados adecuados.
 */
function sendJson(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Responde inmediatamente a solicitudes OPTIONS (CORS preflight).
 */
function handleOptions(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        sendJson(['ok' => true]);
    }
}

/**
 * Obtiene el cuerpo de la petición parseado como JSON o desde $_POST.
 */
function getJsonBody(): ?array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && trim($raw) !== '') {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    return null;
}

// Centraliza la conexión obligatoria de las rutas de API y comunica indisponibilidad al cliente.
function requireApiDatabase(): PDO
{
    $db = getDBConnection();
    if ($db === null) {
        sendJson(['ok' => false, 'error' => 'database_unavailable', 'message' => 'No se pudo conectar a la base de datos.'], 503);
    }
    return $db;
}

/**
 * Obtiene el método HTTP en mayúsculas.
 */
function requestMethod(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

/**
 * Verifica si hay una sesión activa de usuario en la API.
 */
function getApiUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

// Además de autenticar, renueva el latido persistente usado para detectar sesiones activas.
/**
 * Protege un endpoint de la API exigiendo una sesión activa.
 */
function requireApiAuth(): void
{
    $user = getApiUser();
    if (!is_array($user)) {
        sendJson([
            'ok' => false,
            'error' => 'unauthorized',
            'message' => 'Debés iniciar sesión para acceder a este recurso.'
        ], 401);
    }

    require_once dirname(__DIR__) . '/includes/jornada.php';
    $db = requireApiDatabase();
    try {
        registrarActividadSesion($db, $user);
    } catch (Exception $e) {
        error_log('Error al actualizar la actividad de la sesión: ' . $e->getMessage());
        sendJson([
            'ok' => false,
            'error' => 'session_tracking_failed',
            'message' => 'No se pudo validar la actividad de la sesión. Intentá nuevamente.'
        ], 503);
    }
}

/**
 * Comprueba si el usuario autenticado en la API es Super Administrador.
 */
function isSuperAdminApi(): bool
{
    $user = getApiUser();
    if (!$user || !is_array($user)) {
        return false;
    }
    $role = strtolower(trim((string)($user['role'] ?? $user['rol'] ?? '')));
    return in_array($role, ['superadmin', 'super administrador', 'super_admin', 'super-admin'], true);
}

/**
 * Comprueba si el usuario autenticado en la API es Administrador o Super Administrador.
 */
function isAdminApi(): bool
{
    $user = getApiUser();
    if (!$user || !is_array($user)) {
        return false;
    }
    if (isSuperAdminApi()) {
        return true;
    }
    $role = strtolower(trim((string)($user['role'] ?? $user['rol'] ?? '')));
    return in_array($role, ['administrador', 'admin'], true);
}

// Permite a las rutas aplicar las autorizaciones delegables configuradas desde la consola.
/** Comprueba un permiso delegable consultando la fuente persistente del usuario. */
function hasUserPermissionApi(string $permission): bool
{
    $allowedPermissions = ['puede_registrar_stock', 'puede_modificar_informes'];
    $user = getApiUser();
    if (!is_array($user) || !in_array($permission, $allowedPermissions, true)) {
        return false;
    }

    $db = getDBConnection();
    if ($db === null) {
        return false;
    }

    $stmt = $db->prepare("SELECT `{$permission}` FROM `usuario` WHERE `id_usuario` = :id OR LOWER(`nombre`) = LOWER(:nombre) LIMIT 1");
    $stmt->execute([
        ':id' => (int)($user['id'] ?? 0),
        ':nombre' => (string)($user['usuario'] ?? '')
    ]);
    return (bool)$stmt->fetchColumn();
}

function canRegisterStockApi(): bool
{
    return isAdminApi() || hasUserPermissionApi('puede_registrar_stock');
}

function requireStockRegistrationApi(): void
{
    if (!canRegisterStockApi()) {
        sendJson(['ok' => false, 'error' => 'forbidden', 'message' => 'No tenés permiso para registrar productos en el stock.'], 403);
    }
}

function canModifyReportsApi(): bool
{
    return isAdminApi() || hasUserPermissionApi('puede_modificar_informes');
}

// Las siguientes guardas terminan la petición temprano si el rol no cubre la operación.
/**
 * Protege un endpoint de la API exigiendo rol de Administrador o superior.
 */
function requireAdminApi(): void
{
    if (!isAdminApi()) {
        sendJson([
            'ok' => false,
            'error' => 'unauthorized',
            'message' => 'Acceso denegado. Se requieren permisos de Administrador.'
        ], 403);
    }
}

/**
 * Protege un endpoint de la API exigiendo EXCLUSIVAMENTE rol de Super Administrador.
 */
function requireSuperAdminApi(): void
{
    if (!isSuperAdminApi()) {
        sendJson([
            'ok' => false,
            'error' => 'superadmin_required',
            'message' => 'Acceso denegado. Se requieren permisos exclusivos de Super Administrador.'
        ], 403);
    }
}

// Agrupa intentos recientes por IP/cuenta para convertir patrones repetidos en alertas persistentes.
function logThreatIfDetected(PDO $db): void
{
    $stmt = $db->query("
        SELECT `usuario`, `detalles`, `fecha`
        FROM `actividad_usuario`
        WHERE `tipo_accion` = 'login_fallido'
          AND `fecha` >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ");
    $userActivities = $stmt->fetchAll();
    $cutoff = time() - 15 * 60;
    $failuresByIp = [];
    $failuresByIpAndUser = [];

    foreach ($userActivities as $item) {
        $eventTime = strtotime((string)($item['fecha'] ?? ''));
        if ($eventTime === false || $eventTime < $cutoff) {
            continue;
        }

        $details = json_decode((string)($item['detalles'] ?? ''), true);
        $details = is_array($details) ? $details : [];
        $ip = trim((string)($details['ip'] ?? ''));
        $username = trim((string)($item['usuario'] ?? 'Desconocido'));
        $pairKey = strtolower($ip) . '|' . strtolower($username);
        $failuresByIpAndUser[$pairKey] ??= ['ip' => $ip, 'usuario' => $username, 'eventos' => []];
        $failuresByIpAndUser[$pairKey]['eventos'][] = $item;

        if ($ip !== '') {
            $failuresByIp[$ip] ??= [];
            $failuresByIp[$ip][] = $item;
        }
    }

    foreach ($failuresByIpAndUser as $key => $group) {
        if (count($group['eventos']) >= 5) {
            appendThreatOnce($db, [
                'regla' => 'fallos_cuenta_ip',
                'clave' => $key,
                'titulo' => 'Múltiples fallos para la misma cuenta',
                'descripcion' => count($group['eventos']) . ' intentos fallidos para ' . $group['usuario'] . ' desde ' . ($group['ip'] !== '' ? $group['ip'] : 'una IP no registrada') . '.',
                'usuario' => $group['usuario'],
                'ip' => $group['ip'] !== '' ? $group['ip'] : null,
                'intentos' => count($group['eventos']),
                'fecha' => date('c'),
                'ventana_minutos' => 15
            ]);
        }
    }

    foreach ($failuresByIp as $ip => $events) {
        $affectedUsers = array_unique(array_map(
            static fn(array $item): string => strtolower((string)($item['usuario'] ?? '')),
            $events
        ));
        if (count($events) >= 10 && count($affectedUsers) >= 3) {
            appendThreatOnce($db, [
                'regla' => 'fallos_multiples_cuentas',
                'clave' => strtolower($ip),
                'titulo' => 'Posible intento sobre varias cuentas',
                'descripcion' => count($events) . ' intentos fallidos sobre ' . count($affectedUsers) . ' cuentas desde ' . $ip . '.',
                'usuario' => null,
                'ip' => $ip,
                'intentos' => count($events),
                'fecha' => date('c'),
                'ventana_minutos' => 15
            ]);
        }
    }
}

function appendThreatOnce(PDO $db, array $threat): void
{
    $duplicate = $db->prepare("
        SELECT 1 FROM `amenaza`
        WHERE `regla` = :regla AND `clave` = :clave
          AND `fecha` >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        LIMIT 1
    ");
    $duplicate->execute([':regla' => $threat['regla'], ':clave' => $threat['clave']]);
    if ($duplicate->fetchColumn()) {
        return;
    }

    $insert = $db->prepare("
        INSERT INTO `amenaza`
            (`id_amenaza`, `regla`, `clave`, `titulo`, `descripcion`, `usuario`, `ip`, `intentos`, `fecha`, `ventana_minutos`)
        VALUES (:id, :regla, :clave, :titulo, :descripcion, :usuario, :ip, :intentos, :fecha, :ventana)
    ");
    $insert->execute([
        ':id' => hash('sha256', $threat['regla'] . '|' . $threat['clave'] . '|' . $threat['fecha']),
        ':regla' => $threat['regla'],
        ':clave' => $threat['clave'],
        ':titulo' => $threat['titulo'],
        ':descripcion' => $threat['descripcion'],
        ':usuario' => $threat['usuario'],
        ':ip' => $threat['ip'],
        ':intentos' => $threat['intentos'],
        ':fecha' => date('Y-m-d H:i:s', strtotime($threat['fecha']) ?: time()),
        ':ventana' => $threat['ventana_minutos']
    ]);
}

/**
 * Registra una acción o interacción de usuario en MySQL.
 */
// Persiste acciones de la aplicación y actualiza las reglas de alerta al registrar un fallo de login.
function logActivity(string $usuario, string $tipo, string $descripcion, ?array $detalles = null, ?int $idUsuario = null): bool
{
    $fecha = date('Y-m-d H:i:s');
    $detallesJson = $detalles !== null ? json_encode($detalles, JSON_UNESCAPED_UNICODE) : null;

    $db = requireApiDatabase();
    $stmt = $db->prepare("
        INSERT INTO `actividad_usuario` (`id_usuario`, `usuario`, `tipo_accion`, `descripcion`, `detalles`, `fecha`)
        VALUES (:id_user, :usuario, :tipo, :desc, :detalles, :fecha)
    ");
    $stmt->execute([
        ':id_user'  => $idUsuario,
        ':usuario'  => $usuario,
        ':tipo'     => $tipo,
        ':desc'     => $descripcion,
        ':detalles' => $detallesJson,
        ':fecha'    => $fecha
    ]);

    if ($tipo === 'login_fallido') {
        logThreatIfDetected($db);
    }

    return true;
}

// Ejecuta la inspección temprana de solicitudes entrantes para que sus señales lleguen a auditoría.
monitorRequestForMaliciousInput();