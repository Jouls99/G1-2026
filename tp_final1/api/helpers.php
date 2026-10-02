<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/database/conexion.php';

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

/**
 * Lee un archivo JSON de forma segura con bloqueo compartido.
 */
function readJsonFile(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }

    $fp = fopen($path, 'r');
    if (!$fp) {
        return [];
    }

    // Bloqueo compartido para lectura
    flock($fp, LOCK_SH);
    $size = filesize($path);
    $content = $size > 0 ? fread($fp, $size) : '';
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($content === false || trim($content) === '') {
        return [];
    }

    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

/**
 * Guarda un array en un archivo JSON de forma atómica y segura con bloqueo exclusivo.
 */
function writeJsonFile(string $path, array $data): bool
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    // Escritura segura mediante archivo temporal y renombrado atómico o con LOCK_EX
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $fp = fopen($path, 'c+');
    if (!$fp) {
        return false;
    }

    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json . PHP_EOL);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }

    fclose($fp);
    return false;
}

/**
 * Retorna la ruta absoluta a un archivo de datos.
 */
function dataPath(string $filename): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $filename;
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

/**
 * Protege un endpoint de la API exigiendo una sesión activa.
 */
function requireApiAuth(): void
{
    if (!is_array(getApiUser())) {
        sendJson([
            'ok' => false,
            'error' => 'unauthorized',
            'message' => 'Debés iniciar sesión para acceder a este recurso.'
        ], 401);
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

/** Comprueba un permiso delegable consultando la fuente persistente del usuario. */
function hasUserPermissionApi(string $permission): bool
{
    $allowedPermissions = ['puede_registrar_stock', 'puede_modificar_informes'];
    $user = getApiUser();
    if (!is_array($user) || !in_array($permission, $allowedPermissions, true)) {
        return false;
    }

    $db = getDBConnection();
    if ($db !== null) {
        try {
            $stmt = $db->prepare("SELECT `{$permission}` FROM `usuario` WHERE `id_usuario` = :id OR LOWER(`nombre`) = LOWER(:nombre) LIMIT 1");
            $stmt->execute([
                ':id' => (int)($user['id'] ?? 0),
                ':nombre' => (string)($user['usuario'] ?? '')
            ]);
            $value = $stmt->fetchColumn();
            if ($value !== false) {
                return (bool)$value;
            }
        } catch (Exception $e) {
            // El respaldo JSON se consulta si MySQL no está disponible.
        }
    }

    $users = readJsonFile(dataPath('users.json'));
    foreach ($users as $storedUser) {
        if ((isset($storedUser['id']) && (int)$storedUser['id'] === (int)($user['id'] ?? 0))
            || strcasecmp((string)($storedUser['usuario'] ?? ''), (string)($user['usuario'] ?? '')) === 0) {
            return !empty($storedUser[$permission]);
        }
    }

    return !empty($user[$permission]);
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

function appendJsonRecord(string $filename, array $record, int $limit = 1000): bool
{
    $path = dataPath($filename);
    $records = readJsonFile($path);
    $records[] = $record;

    if (count($records) > $limit) {
        $records = array_slice($records, -$limit);
    }

    return writeJsonFile($path, $records);
}

function logThreatIfDetected(array $activity): void
{
    $userActivities = readJsonFile(dataPath('actividad_usuarios.json'));
    $cutoff = time() - 15 * 60;
    $failuresByIp = [];
    $failuresByIpAndUser = [];

    foreach ($userActivities as $item) {
        if (($item['tipo_accion'] ?? '') !== 'login_fallido') {
            continue;
        }

        $eventTime = strtotime((string)($item['fecha'] ?? ''));
        if ($eventTime === false || $eventTime < $cutoff) {
            continue;
        }

        $details = is_array($item['detalles'] ?? null) ? $item['detalles'] : [];
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

    $threats = readJsonFile(dataPath('amenazas.json'));
    foreach ($failuresByIpAndUser as $key => $group) {
        if (count($group['eventos']) >= 5) {
            $threats = appendThreatOnce($threats, [
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
            $threats = appendThreatOnce($threats, [
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

function appendThreatOnce(array $threats, array $threat): array
{
    $recentDuplicate = array_filter($threats, static function (array $existing) use ($threat): bool {
        $generatedAt = strtotime((string)($existing['fecha'] ?? ''));
        return ($existing['regla'] ?? '') === $threat['regla']
            && ($existing['clave'] ?? '') === $threat['clave']
            && $generatedAt !== false
            && $generatedAt >= time() - 15 * 60;
    });

    if ($recentDuplicate !== []) {
        return $threats;
    }

    $threat['id'] = hash('sha256', $threat['regla'] . '|' . $threat['clave'] . '|' . $threat['fecha']);
    $threats[] = $threat;
    if (count($threats) > 1000) {
        $threats = array_slice($threats, -1000);
    }
    writeJsonFile(dataPath('amenazas.json'), $threats);

    return $threats;
}

/**
 * Registra una acción / interacción de usuario en MySQL y archivo JSON de respaldo.
 */
function logActivity(string $usuario, string $tipo, string $descripcion, ?array $detalles = null, ?int $idUsuario = null): bool
{
    $fecha = date('Y-m-d H:i:s');
    $detallesJson = $detalles !== null ? json_encode($detalles, JSON_UNESCAPED_UNICODE) : null;

    // 1. Intentar registrar en MySQL
    try {
        $db = getDBConnection();
        if ($db) {
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
        }
    } catch (Exception $e) {
        // Continuar con respaldo JSON
    }

    // 2. Mantener una copia de respaldo por tipo de actividad
    try {
        $activity = [
            'id'          => time() . rand(100, 999),
            'id_usuario'  => $idUsuario,
            'usuario'     => $usuario,
            'tipo_accion' => $tipo,
            'descripcion' => $descripcion,
            'detalles'    => $detalles,
            'fecha'       => date('c')
        ];

        if ($tipo === 'venta_registrada') {
            appendJsonRecord('actividad_ventas.json', $activity);
        } elseif (in_array($tipo, ['login_exitoso', 'login_fallido', 'logout'], true)) {
            appendJsonRecord('actividad_usuarios.json', $activity);
            if ($tipo === 'login_fallido') {
                logThreatIfDetected($activity);
            }
        } else {
            appendJsonRecord('actividades.json', $activity);
        }
    } catch (Throwable $e) {
        // Ignorar
    }

    return true;
}

