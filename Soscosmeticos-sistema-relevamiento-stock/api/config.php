<?php
/**
 * Configuración y funciones auxiliares para la API
 */

// Iniciar sesión si no está iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Configurar encabezados CORS y JSON
// Configurar encabezados comunes para que los clientes reciban JSON y puedan hacer solicitudes API.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Manejar preflight OPTIONS
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Directorios de datos
// Centralizar las rutas compartidas por los endpoints que persisten inventario, ventas y usuarios.
define('ROOT_DIR', dirname(__DIR__));
define('DATA_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . 'data');

// Asegurar que la carpeta data exista
// Preparar el directorio de almacenamiento al cargar la configuración, antes de cualquier escritura.
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0777, true);
}

// Rutas de archivos de datos (con fallback a la raíz si existen allí)
// Resolver primero data/ y aceptar archivos heredados ubicados en la raíz del proyecto.
function getDataFilePath($filename) {
    $dataPath = DATA_DIR . DIRECTORY_SEPARATOR . $filename;
    $rootPath = ROOT_DIR . DIRECTORY_SEPARATOR . $filename;

    if (file_exists($dataPath)) {
        return $dataPath;
    }
    if (file_exists($rootPath)) {
        return $rootPath;
    }
    return $dataPath;
}

/**
 * Leer archivo JSON de forma segura con bloqueo compartido
 * Leer un archivo JSON bajo bloqueo compartido y usar un valor alternativo ante ausencia o contenido inválido.
 * Los endpoints usan este helper para consultar sus ficheros de datos.
 */
function readJsonFile($filepath, $default = []) {
    if (!file_exists($filepath)) {
        return $default;
    }
    
    $fp = fopen($filepath, 'rb');
    if (!$fp) {
        return $default;
    }
    
    flock($fp, LOCK_SH);
    $content = '';
    while (!feof($fp)) {
        $content .= fread($fp, 8192);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    
    $data = json_decode($content, true);
    return is_array($data) ? $data : $default;
}

/**
 * Guardar datos en archivo JSON con bloqueo exclusivo y escritura atómica
 * Crear el directorio si hace falta y reemplazar el JSON mediante un temporal y bloqueo exclusivo.
 * Los endpoints usan el resultado booleano para informar errores de persistencia.
 */
function writeJsonFile($filepath, $data) {
    $dir = dirname($filepath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    
    $tempFile = $filepath . '.' . uniqid('tmp_', true);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if ($json === false) {
        return false;
    }
    
    $fp = fopen($tempFile, 'wb');
    if (!$fp) {
        return false;
    }
    
    flock($fp, LOCK_EX);
    fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    
    return rename($tempFile, $filepath);
}

/**
 * Obtener payload JSON de la petición HTTP
 * Decodificar el cuerpo JSON entrante; los endpoints consumen este resultado para validar cada operación.
 */
function getJsonInput() {
    $input = file_get_contents('php://input');
    if (empty($input)) {
        return null;
    }
    return json_decode($input, true);
}

/**
 * Responder con JSON y código de estado HTTP
 * Emitir una respuesta JSON exitosa con su estado y cerrar la petición desde el endpoint actual.
 */
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Responder con error JSON
 * Emitir un error JSON opcionalmente codificado y cerrar la petición desde el endpoint actual.
 */
function sendJsonError($message, $statusCode = 400, $code = null) {
    http_response_code($statusCode);
    $response = ['error' => $message];
    if ($code !== null) {
        $response['code'] = $code;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Verificar si el usuario está autenticado en la API
 * Consultar el estado de autenticación de la sesión iniciada al cargar este archivo.
 */
function is_api_authenticated() {
    return !empty($_SESSION['logged_in']) && !empty($_SESSION['usuario']);
}

/**
 * Exigir autenticación para acceder al endpoint
 * Rechazar con 401 a clientes sin sesión; lo invocan los endpoints que protegen datos de negocio.
 */
function require_api_auth() {
    if (!is_api_authenticated()) {
        sendJsonError('unauthorized', 401);
    }
}

