<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

handleOptions();

$action = $_GET['action'] ?? ($_POST['action'] ?? 'check');
$file = dataPath('users.json');
$method = requestMethod();
$db = getDBConnection();

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
    $body = getJsonBody();
    $usuario = trim((string) ($body['usuario'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($usuario === '' || $password === '') {
        sendJson(['ok' => false, 'error' => 'missing_fields', 'message' => 'Completá usuario y contraseña.'], 400);
    }

    try {
        // Consultar base de datos MySQL
        $stmt = $db->prepare("SELECT `id_usuario`, `nombre`, `contraseña`, `rol` FROM `usuario` WHERE LOWER(`nombre`) = LOWER(:nombre) LIMIT 1");
        $stmt->execute([':nombre' => $usuario]);
        $found = $stmt->fetch();

        if (!$found || (string)($found['contraseña'] ?? '') !== $password) {
            sendJson(['ok' => false, 'error' => 'invalid_credentials', 'message' => 'Usuario o contraseña incorrectos.'], 401);
        }

        // Iniciar sesión en PHP
        $_SESSION['user'] = [
            'id'      => (int)$found['id_usuario'],
            'usuario' => $found['nombre'],
            'role'    => $found['rol'] ?? 'vendedor',
            'loginAt' => date('c')
        ];

        // Actualizar último acceso en MySQL
        try {
            $updStmt = $db->prepare("UPDATE `usuario` SET `ultimo_acceso` = NOW() WHERE `id_usuario` = :id");
            $updStmt->execute([':id' => (int)$found['id_usuario']]);
        } catch (Exception $e) {
            // Ignorar
        }

        // Sincronizar último acceso en users.json
        try {
            $users = readJsonFile($file);
            foreach ($users as &$u) {
                if (strcasecmp((string)($u['usuario'] ?? ''), $usuario) === 0) {
                    $u['lastLogin'] = date('c');
                }
            }
            writeJsonFile($file, $users);
        } catch (Exception $e) {
            // Ignorar
        }

        // Registrar interacción de inicio de sesión
        logActivity(
            $found['nombre'],
            'login_exitoso',
            "Inicio de sesión exitoso del usuario '{$found['nombre']}' (Rol: {$found['rol']})",
            ['ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'],
            (int)$found['id_usuario']
        );

        sendJson([
            'ok' => true,
            'message' => 'Inicio de sesión exitoso.',
            'user' => $_SESSION['user']
        ]);
    } catch (Exception $e) {
        sendJson(['ok' => false, 'error' => 'db_error', 'message' => 'Error al conectar con la base de datos: ' . $e->getMessage()], 500);
    }
}

// 3. Registrar nuevo usuario en MySQL
if ($action === 'register' && $method === 'POST') {
    $body = getJsonBody();
    $usuario = trim((string) ($body['usuario'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $role = trim((string) ($body['role'] ?? 'vendedor'));
    if ($role === '') {
        $role = 'vendedor';
    }

    if ($usuario === '' || $password === '') {
        sendJson(['ok' => false, 'error' => 'missing_fields', 'message' => 'Completá todos los campos.'], 400);
    }

    try {
        // Comprobar si ya existe en MySQL
        $checkStmt = $db->prepare("SELECT `id_usuario` FROM `usuario` WHERE LOWER(`nombre`) = LOWER(:nombre) LIMIT 1");
        $checkStmt->execute([':nombre' => $usuario]);
        if ($checkStmt->fetch()) {
            sendJson(['ok' => false, 'error' => 'user_exists', 'message' => 'Ese usuario ya existe.'], 409);
        }

        $insertStmt = $db->prepare("INSERT INTO `usuario` (`nombre`, `contraseña`, `rol`, `ultimo_acceso`, `fecha_creacion`) VALUES (:nombre, :pass, :rol, NOW(), NOW())");
        $insertStmt->execute([
            ':nombre' => $usuario,
            ':pass'   => $password,
            ':rol'    => $role
        ]);
        $newUserId = (int)$db->lastInsertId();

        // Iniciar sesión automáticamente tras registro
        $_SESSION['user'] = [
            'id'      => $newUserId,
            'usuario' => $usuario,
            'role'    => $role,
            'loginAt' => date('c')
        ];

        // Sincronizar archivo JSON como respaldo
        $users = readJsonFile($file);
        $users[] = [
            'id'        => $newUserId,
            'usuario'   => $usuario,
            'password'  => $password,
            'role'      => $role,
            'createdAt' => date('c'),
            'lastLogin' => date('c')
        ];
        writeJsonFile($file, $users);

        // Registrar interacción de registro
        logActivity(
            $usuario,
            'registro_usuario',
            "Nuevo usuario registrado en la plataforma: '{$usuario}' con rol {$role}",
            ['rol' => $role],
            $newUserId
        );

        sendJson([
            'ok' => true,
            'message' => 'Usuario registrado exitosamente.',
            'user' => $_SESSION['user']
        ]);
    } catch (Exception $e) {
        sendJson(['ok' => false, 'error' => 'db_error', 'message' => 'Error al registrar usuario en la base de datos: ' . $e->getMessage()], 500);
    }
}

// 4. Cerrar sesión
if ($action === 'logout') {
    $uName = $_SESSION['user']['usuario'] ?? 'Usuario';
    $uId = $_SESSION['user']['id'] ?? null;
    logActivity($uName, 'logout', "Cierre de sesión de '{$uName}'", null, $uId ? (int)$uId : null);

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

    sendJson([
        'ok' => true,
        'message' => 'Sesión cerrada correctamente.'
    ]);
}

sendJson(['ok' => false, 'error' => 'invalid_action'], 400);
