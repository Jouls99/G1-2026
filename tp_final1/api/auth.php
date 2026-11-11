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
    if (is_array($user)) {
        try {
            if (!validateCurrentUserSession()) {
                $user = null;
            }
        } catch (Throwable $e) {
            error_log('No se pudo validar la sesión actual: ' . $e->getMessage());
            sendJson(['ok' => false, 'error' => 'session_validation_failed', 'message' => 'No se pudo validar la sesión. Intentá nuevamente.'], 503);
        }
    }
    sendJson([
        'ok' => true,
        'loggedIn' => $user !== null,
        'user' => $user
    ]);
}

// 2. Iniciar sesión (Login)
// Verifica acceso, limita intentos fallidos, crea la sesión y registra la auditoría.
if ($action === 'login' && $method === 'POST') {
    if (is_array(getApiUser())) {
        try {
            if (validateCurrentUserSession()) {
                sendJson([
                    'ok' => false,
                    'error' => 'already_logged_in',
                    'message' => 'Ya hay una sesión activa. Cerrá sesión para ingresar con otra cuenta.'
                ], 400);
            }
        } catch (Throwable $e) {
            error_log('No se pudo validar la sesión previa al inicio de sesión: ' . $e->getMessage());
            sendJson(['ok' => false, 'error' => 'session_validation_failed', 'message' => 'No se pudo validar la sesión. Intentá nuevamente.'], 503);
        }
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

    $stmt = $db->prepare("SELECT `id_usuario`, `nombre`, `password`, `rol`, `estado` FROM `usuario` WHERE LOWER(TRIM(`nombre`)) = LOWER(:nombre) LIMIT 1");
    $stmt->execute([':nombre' => $usuario]);
    $found = $stmt->fetch();

    $clientIp = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    if (strpos($clientIp, ',') !== false) {
        $clientIp = trim(explode(',', $clientIp)[0]);
    }
    $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? 'Navegador Web');

    if ($found && strtolower((string)($found['estado'] ?? 'habilitado')) !== 'habilitado') {
        sendJson(['ok' => false, 'error' => 'user_disabled', 'message' => 'La cuenta está deshabilitada.'], 403);
    }

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

    $storedPasswordHash = (string)$found['password'];
    if (userPasswordNeedsRehash($storedPasswordHash)) {
        $rehashStmt = $db->prepare("UPDATE `usuario` SET `password` = :password WHERE `id_usuario` = :id");
        $rehashStmt->execute([
            ':password' => hashUserPassword($password),
            ':id' => (int)$found['id_usuario']
        ]);
    }

    $clearAttempts = $db->prepare("DELETE FROM `login_intentos` WHERE `usuario_clave` = :usuario");
    $clearAttempts->execute([':usuario' => trim($usuario)]);

    // Rota el identificador de sesión para evitar reutilizar uno previo a la autenticación.
    session_regenerate_id(true);

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
    if ($method !== 'POST') {
        header('Allow: POST, OPTIONS');
        sendJson(['ok' => false, 'error' => 'method_not_allowed', 'message' => 'El cierre de sesión requiere POST.'], 405);
    }

    $currentUser = getApiUser();
    if (!is_array($currentUser)) {
        sendJson(['ok' => false, 'error' => 'unauthorized', 'message' => 'No hay una sesión activa para cerrar.'], 401);
    }
    try {
        if (!validateCurrentUserSession()) {
            sendJson(['ok' => true, 'message' => 'La sesión revocada se cerró correctamente.']);
        }
    } catch (Throwable $e) {
        error_log('No se pudo validar la sesión antes del cierre: ' . $e->getMessage());
        sendJson(['ok' => false, 'error' => 'session_validation_failed', 'message' => 'No se pudo validar la sesión. Intentá nuevamente.'], 503);
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

    destroySecureSession();

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
