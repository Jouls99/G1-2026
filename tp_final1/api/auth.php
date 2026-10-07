<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once dirname(__DIR__) . '/includes/jornada.php';

handleOptions();

$action = $_GET['action'] ?? ($_POST['action'] ?? 'check');
$method = requestMethod();
$db = requireApiDatabase();

// 1. Comprobar estado de autenticación
if ($action === 'check') {
    $user = getApiUser();
    sendJson([
        'ok' => true,
        'loggedIn' => $user !== null,
        'user' => $user
    ]);
}

// 2. Iniciar sesión (Login)
if ($action === 'login' && $method === 'POST') {
    if (is_array(getApiUser()) && !isSuperAdminApi()) {
        sendJson([
            'ok' => false,
            'error' => 'forbidden',
            'message' => 'Solo el Super Administrador puede cambiar de usuario mientras tiene una sesión activa.'
        ], 403);
    }

    $body = getJsonBody();
    $usuario = trim((string) ($body['usuario'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($usuario === '' || $password === '') {
        sendJson(['ok' => false, 'error' => 'missing_fields', 'message' => 'Completá usuario y contraseña.'], 400);
    }

    $stmt = $db->prepare("SELECT `id_usuario`, `nombre`, `password`, `rol` FROM `usuario` WHERE LOWER(TRIM(`nombre`)) = LOWER(:nombre) LIMIT 1");
    $stmt->execute([':nombre' => $usuario]);
    $found = $stmt->fetch();

    $clientIp = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    if (strpos($clientIp, ',') !== false) {
        $clientIp = trim(explode(',', $clientIp)[0]);
    }
    $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? 'Navegador Web');

    if (!$found || !password_verify($password, (string)($found['password'] ?? ''))) {
        // Registrar intento fallido para auditoría del Super Admin
        logActivity(
            $usuario !== '' ? $usuario : 'Desconocido',
            'login_fallido',
            "Intento de inicio de sesión fallido para el usuario '{$usuario}'",
            [
                'ip' => $clientIp,
                'user_agent' => $userAgent,
                'motivo' => 'Credenciales incorrectas'
            ]
        );
        sendJson(['ok' => false, 'error' => 'invalid_credentials', 'message' => 'Usuario o contraseña incorrectos.'], 401);
    }

    // Iniciar sesión en PHP
    $_SESSION['user'] = [
        'id'      => (int)$found['id_usuario'],
        'usuario' => $found['nombre'],
        'role'    => $found['rol'] ?? 'vendedor',
        'loginAt' => date('c')
    ];
    try {
        registrarActividadSesion($db, $_SESSION['user']);
    } catch (Exception $e) {
        unset($_SESSION['user']);
        error_log('Error al registrar la sesión activa: ' . $e->getMessage());
        sendJson([
            'ok' => false,
            'error' => 'session_tracking_failed',
            'message' => 'No se pudo iniciar la sesión correctamente. Intentá nuevamente.'
        ], 500);
    }

    // Actualizar último acceso en MySQL
    $updStmt = $db->prepare("UPDATE `usuario` SET `ultimo_acceso` = NOW() WHERE `id_usuario` = :id");
    $updStmt->execute([':id' => (int)$found['id_usuario']]);

    // Registrar interacción de inicio de sesión con datos completos
    logActivity(
        $found['nombre'],
        'login_exitoso',
        "Inicio de sesión exitoso del usuario '{$found['nombre']}' (Rol: {$found['rol']})",
        [
            'ip' => $clientIp,
            'user_agent' => $userAgent,
            'rol' => $found['rol'] ?? 'vendedor',
            'timestamp' => date('Y-m-d H:i:s')
        ],
        (int)$found['id_usuario']
    );

    sendJson([
        'ok' => true,
        'message' => 'Inicio de sesión exitoso.',
        'user' => $_SESSION['user']
    ]);
}

// 3. Cerrar sesión
if ($action === 'logout') {
    $currentUser = getApiUser();
    if (!is_array($currentUser)) {
        sendJson(['ok' => false, 'error' => 'unauthorized', 'message' => 'No hay una sesión activa para cerrar.'], 401);
    }
    $uName = $currentUser['usuario'] ?? 'Usuario';
    $uId = $currentUser['id'] ?? null;
    logActivity($uName, 'logout', "Cierre de sesión de '{$uName}'", null, $uId ? (int)$uId : null);
    $jornadaCerrada = false;
    $errorCierreJornada = false;
    try {
        $closeResult = cerrarSesionYJornadaSiCorresponde($db, $currentUser);
        $jornadaCerrada = $closeResult['closed'];
        if ($jornadaCerrada) {
            logActivity(
                $uName,
                'cierre_jornada',
                "Cierre de jornada: se archivaron {$closeResult['archived']} registros de ventas y se depuraron {$closeResult['pruned']} registros antiguos.",
                [
                    'registros_archivados' => $closeResult['archived'],
                    'registros_depurados' => $closeResult['pruned']
                ],
                $uId ? (int)$uId : null
            );
        }
    } catch (Exception $e) {
        $errorCierreJornada = true;
        error_log('Error al cerrar la jornada y eliminar ventas: ' . $e->getMessage());
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

    if ($errorCierreJornada) {
        sendJson([
            'ok' => false,
            'error' => 'day_close_failed',
            'message' => 'La sesión se cerró, pero no se pudo completar el cierre de jornada. Contactá a un administrador.'
        ], 500);
    }

    sendJson([
        'ok' => true,
        'message' => $jornadaCerrada
            ? 'Sesión cerrada y jornada finalizada correctamente.'
            : 'Sesión cerrada correctamente.'
    ]);
}

sendJson(['ok' => false, 'error' => 'invalid_action'], 400);
