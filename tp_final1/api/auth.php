<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once dirname(__DIR__) . '/includes/jornada.php';

handleOptions();

$action = $_GET['action'] ?? ($_POST['action'] ?? 'check');
$method = requestMethod();
$db = requireApiDatabase();

// El control de bloqueo se consulta en cada intento antes de verificar credenciales.
function getLoginLockSeconds(PDO $db, string $username): int
{
    $stmt = $db->prepare("
        SELECT GREATEST(1, TIMESTAMPDIFF(SECOND, NOW(), `bloqueado_hasta`))
        FROM `login_intentos`
        WHERE `usuario_clave` = :usuario
          AND `bloqueado_hasta` > NOW()
        LIMIT 1
    ");
    $stmt->execute([':usuario' => trim($username)]);
    return (int)($stmt->fetchColumn() ?: 0);
}

// Actualiza de forma atómica los fallos consecutivos y activa un bloqueo tras el umbral.
function recordFailedLogin(PDO $db, string $username): int
{
    $db->beginTransaction();
    try {
        $insert = $db->prepare("
            INSERT IGNORE INTO `login_intentos` (`usuario_clave`, `intentos_fallidos`)
            VALUES (:usuario, 0)
        ");
        $insert->execute([':usuario' => trim($username)]);

        $select = $db->prepare("
            SELECT `intentos_fallidos`, `bloqueado_hasta`,
                   (`bloqueado_hasta` > NOW()) AS `esta_bloqueado`
            FROM `login_intentos`
            WHERE `usuario_clave` = :usuario
            FOR UPDATE
        ");
        $select->execute([':usuario' => trim($username)]);
        $state = $select->fetch();

        if (!$state) {
            throw new RuntimeException('No se pudo recuperar el contador de intentos de inicio de sesión.');
        }

        if ((int)$state['esta_bloqueado'] === 1) {
            $db->commit();
            return getLoginLockSeconds($db, $username);
        }

        $attempts = $state['bloqueado_hasta'] !== null
            ? 1
            : (int)$state['intentos_fallidos'] + 1;
        $locked = $attempts >= 4;
        $update = $db->prepare("
            UPDATE `login_intentos`
            SET `intentos_fallidos` = :intentos,
                `bloqueado_hasta` = CASE WHEN :bloquear = 1 THEN DATE_ADD(NOW(), INTERVAL 10 SECOND) ELSE NULL END,
                `actualizado_en` = NOW()
            WHERE `usuario_clave` = :usuario
        ");
        $update->execute([
            ':intentos' => $attempts,
            ':bloquear' => $locked ? 1 : 0,
            ':usuario' => trim($username)
        ]);
        $db->commit();

        return $locked ? 10 : 0;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

// 1. Comprobar estado de autenticación
// Devuelve al cliente el usuario reconocido por la sesión PHP actual.
if ($action === 'check') {
    $user = getApiUser();
    sendJson([
        'ok' => true,
        'loggedIn' => $user !== null,
        'user' => $user
    ]);
}

// 2. Iniciar sesión (Login)
// Verifica acceso, limita intentos fallidos, crea la sesión y registra la auditoría.
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

    $lockSeconds = getLoginLockSeconds($db, $usuario);
    if ($lockSeconds > 0) {
        header('Retry-After: ' . $lockSeconds);
        logActivity(
            $usuario,
            'login_fallido',
            "Intento de inicio de sesión rechazado durante el bloqueo temporal del usuario '{$usuario}'",
            [
                'motivo' => 'Bloqueo temporal por intentos fallidos consecutivos',
                'segundos_restantes' => $lockSeconds
            ]
        );
        sendJson([
            'ok' => false,
            'error' => 'login_temporarily_locked',
            'message' => "Acceso temporalmente bloqueado. Esperá {$lockSeconds} segundos antes de volver a intentarlo.",
            'retry_after' => $lockSeconds
        ], 429);
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
        $lockSeconds = recordFailedLogin($db, $usuario);
        // Registrar intento fallido para auditoría del Super Admin
        logActivity(
            $usuario !== '' ? $usuario : 'Desconocido',
            'login_fallido',
            "Intento de inicio de sesión fallido para el usuario '{$usuario}'",
            [
                'ip' => $clientIp,
                'user_agent' => $userAgent,
                'motivo' => $lockSeconds > 0
                    ? 'Credenciales incorrectas; se activó un bloqueo temporal de 10 segundos'
                    : 'Credenciales incorrectas'
            ]
        );
        if ($lockSeconds > 0) {
            header('Retry-After: ' . $lockSeconds);
            sendJson([
                'ok' => false,
                'error' => 'login_temporarily_locked',
                'message' => 'Se alcanzaron 4 intentos fallidos consecutivos. El acceso quedó bloqueado durante 10 segundos.',
                'retry_after' => $lockSeconds
            ], 429);
        }
        sendJson(['ok' => false, 'error' => 'invalid_credentials', 'message' => 'Usuario o contraseña incorrectos.'], 401);
    }

    $clearAttempts = $db->prepare("DELETE FROM `login_intentos` WHERE `usuario_clave` = :usuario");
    $clearAttempts->execute([':usuario' => trim($usuario)]);

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
// Registra salida, intenta el cierre de jornada y elimina cookie/datos de sesión.
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
