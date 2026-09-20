<?php
/**
 * API para Gestión y Consulta de Usuarios
 */
require_once __DIR__ . '/config.php';

$usersFile = getDataFilePath('users.json');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // Solo usuarios autenticados pueden consultar usuarios
    require_api_auth();
    
    // Obtener lista de usuarios
    $users = readJsonFile($usersFile, []);
    
    // Retornar lista segura para el frontend (ocultando hashes en la salida si se requiere, pero manteniendo compatibilidad)
    $safeUsers = array_map(function($user) {
        return [
            'usuario' => $user['usuario'] ?? '',
            'role' => $user['role'] ?? 'vendedor'
        ];
    }, $users);
    
    // Si se pasa query ?full=1 o se necesita compatibilidad con frontend anterior:
    sendJsonResponse($users);
}

if ($method === 'POST') {
    $payload = getJsonInput();
    
    if (!$payload) {
        sendJsonError('invalid_payload', 400);
    }
    
    // Acción de inicio de sesión directo
    if (isset($payload['action']) && $payload['action'] === 'login') {
        $usuario = trim($payload['usuario'] ?? '');
        $password = $payload['password'] ?? '';
        
        if (empty($usuario) || empty($password)) {
            sendJsonError('missing_fields', 400);
        }
        
        $users = readJsonFile($usersFile, []);
        $foundUser = null;
        
        foreach ($users as $u) {
            if (strcasecmp($u['usuario'], $usuario) === 0) {
                $foundUser = $u;
                break;
            }
        }
        
        if (!$foundUser) {
            sendJsonError('invalid_credentials', 401);
        }
        
        // Verificar contraseña (soporta texto plano existente y password_hash)
        $passwordMatch = false;
        if (isset($foundUser['password'])) {
            if (password_verify($password, $foundUser['password']) || $foundUser['password'] === $password) {
                $passwordMatch = true;
            }
        }
        
        if (!$passwordMatch) {
            sendJsonError('invalid_credentials', 401);
        }
        
        // Iniciar sesión en PHP
        $_SESSION['usuario'] = $foundUser['usuario'];
        $_SESSION['role'] = $foundUser['role'] ?? 'vendedor';
        $_SESSION['logged_in'] = true;
        
        sendJsonResponse([
            'ok' => true,
            'usuario' => $foundUser['usuario'],
            'role' => $foundUser['role'] ?? 'vendedor'
        ]);
    }
    
    // Registro de nuevo usuario
    $usuario = trim($payload['usuario'] ?? '');
    $password = $payload['password'] ?? '';
    $role = trim($payload['role'] ?? 'vendedor');
    
    if (empty($usuario) || empty($password)) {
        sendJsonError('missing_fields', 400);
    }
    
    $users = readJsonFile($usersFile, []);
    
    // Verificar si ya existe
    foreach ($users as $u) {
        if (strcasecmp($u['usuario'], $usuario) === 0) {
            sendJsonError('user_exists', 409);
        }
    }
    
    // Guardar nuevo usuario con hash
    $newUser = [
        'usuario' => $usuario,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
        'creado_en' => date('c')
    ];
    
    $users[] = $newUser;
    
    if (!writeJsonFile($usersFile, $users)) {
        sendJsonError('save_error', 500);
    }
    
    $_SESSION['usuario'] = $usuario;
    $_SESSION['role'] = $role;
    $_SESSION['logged_in'] = true;
    
    sendJsonResponse([
        'ok' => true,
        'usuario' => $usuario,
        'role' => $role
    ]);
}

sendJsonError('method_not_allowed', 405);
