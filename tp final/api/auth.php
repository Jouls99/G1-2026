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

    $found = null;

    // 1. Consultar base de datos MySQL si está disponible
    if ($db !== null) {
        try {
            $stmt = $db->prepare("SELECT `id_usuario`, `nombre`, `password`, `rol` FROM `usuario` WHERE LOWER(TRIM(`nombre`)) = LOWER(:nombre) LIMIT 1");
            $stmt->execute([':nombre' => $usuario]);
            $found = $stmt->fetch();
        } catch (Exception $e) {
            $found = null;
        }
    }

    // 2. Si no se encontró en DB o falló, buscar en users.json
    if (!$found || !password_verify($password, (string)($found['password'] ?? ''))) {
        $usersJson = readJsonFile($file);
        $foundJson = null;
        foreach ($usersJson as $u) {
            if (strcasecmp(trim((string)($u['usuario'] ?? '')), $usuario) === 0 && (string)($u['password'] ?? '') === $password) {
                $foundJson = $u;
                break;
            }
        }

        if ($foundJson) {
            $found = [
                'id_usuario' => (int)($foundJson['id'] ?? 1),
                'nombre'     => (string)$foundJson['usuario'],
                'password'   => password_hash((string)$foundJson['password'], PASSWORD_DEFAULT),
                'rol'        => (string)($foundJson['role'] ?? 'vendedor')
            ];

            // Si la DB está disponible, sincronizar el usuario en MySQL
            if ($db !== null) {
                try {
                    $ins = $db->prepare("INSERT INTO `usuario` (`nombre`, `password`, `rol`, `fecha_creacion`) VALUES (:nombre, :pass, :rol, NOW())");
                    $ins->execute([
                        ':nombre' => $found['nombre'],
                        ':pass'   => $found['password'],
                        ':rol'    => $found['rol']
                    ]);
                    $found['id_usuario'] = (int)$db->lastInsertId();
                } catch (Exception $e) {
                    // Ignorar si ya existía
                }
            }
        }
    }

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

    // Actualizar último acceso en MySQL
    if ($db !== null) {
        try {
            $updStmt = $db->prepare("UPDATE `usuario` SET `ultimo_acceso` = NOW() WHERE `id_usuario` = :id");
            $updStmt->execute([':id' => (int)$found['id_usuario']]);
        } catch (Exception $e) {
            // Ignorar
        }
    }

    // Sincronizar último acceso en users.json
    try {
        $users = readJsonFile($file);
        foreach ($users as &$u) {
            if (strcasecmp(trim((string)($u['usuario'] ?? '')), $usuario) === 0) {
                $u['lastLogin'] = date('c');
            }
        }
        writeJsonFile($file, $users);
    } catch (Exception $e) {
        // Ignorar
    }

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

// 3. Registrar nuevo usuario en MySQL y JSON
if ($action === 'register' && $method === 'POST') {
    $body = getJsonBody();
    $usuario = trim((string) ($body['usuario'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    // El administrador se define previamente en el sistema; el registro público
    // solamente puede crear vendedores.
    $role = 'vendedor';

    if ($usuario === '' || $password === '') {
        sendJson(['ok' => false, 'error' => 'missing_fields', 'message' => 'Completá todos los campos.'], 400);
    }

    $newUserId = time();

    // 1. Comprobar si ya existe en MySQL
    if ($db !== null) {
        try {
            $checkStmt = $db->prepare("SELECT `id_usuario` FROM `usuario` WHERE LOWER(TRIM(`nombre`)) = LOWER(:nombre) LIMIT 1");
            $checkStmt->execute([':nombre' => $usuario]);
            if ($checkStmt->fetch()) {
                sendJson(['ok' => false, 'error' => 'user_exists', 'message' => 'Ese usuario ya existe.'], 409);
            }

            $insertStmt = $db->prepare("INSERT INTO `usuario` (`nombre`, `password`, `rol`, `fecha_creacion`, `ultimo_acceso`) VALUES (:nombre, :pass, :rol, NOW(), NOW())");
            $insertStmt->execute([
                ':nombre' => $usuario,
                ':pass'   => password_hash($password, PASSWORD_DEFAULT),
                ':rol'    => $role
            ]);
            $newUserId = (int)$db->lastInsertId();
        } catch (Exception $e) {
            // Continuar con JSON
        }
    }

    // 2. Comprobar en users.json
    $users = readJsonFile($file);
    foreach ($users as $u) {
        if (strcasecmp(trim((string)($u['usuario'] ?? '')), $usuario) === 0) {
            sendJson(['ok' => false, 'error' => 'user_exists', 'message' => 'Ese usuario ya existe.'], 409);
        }
    }

    // Iniciar sesión automáticamente tras registro
    $_SESSION['user'] = [
        'id'      => $newUserId,
        'usuario' => $usuario,
        'role'    => $role,
        'loginAt' => date('c')
    ];

    // Sincronizar archivo JSON como respaldo
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
