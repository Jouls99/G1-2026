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
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . $filename;
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
 * Comprueba si el usuario autenticado en la API es Administrador.
 */
function isAdminApi(): bool
{
    $user = getApiUser();
    if (!$user || !is_array($user)) {
        return false;
    }
    $role = strtolower((string)($user['role'] ?? $user['rol'] ?? ''));
    return in_array($role, ['administrador', 'admin'], true);
}

/**
 * Protege un endpoint de la API exigiendo rol de Administrador.
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

    // 2. Sincronizar en actividades.json como respaldo
    try {
        $actFile = dataPath('actividades.json');
        $actividades = readJsonFile($actFile);
        $actividades[] = [
            'id'          => time() . rand(100, 999),
            'id_usuario'  => $idUsuario,
            'usuario'     => $usuario,
            'tipo_accion' => $tipo,
            'descripcion' => $descripcion,
            'detalles'    => $detalles,
            'fecha'       => date('c')
        ];
        // Mantener las últimas 1000 actividades
        if (count($actividades) > 1000) {
            $actividades = array_slice($actividades, -1000);
        }
        writeJsonFile($actFile, $actividades);
    } catch (Exception $e) {
        // Ignorar
    }

    return true;
}

