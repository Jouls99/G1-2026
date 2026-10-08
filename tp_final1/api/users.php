<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

handleOptions();

$method = requestMethod();
$db = requireApiDatabase();
$currentUser = getApiUser();

// Administradores y Super Administradores pueden gestionar cuentas.
requireAdminApi();

// Funciones auxiliares para mantener roles únicos y aplicar límites de administración de cuentas.
function roleHasOccupant(PDO $db, string $role, int $exceptUserId = 0): bool
{
    $aliases = $role === 'superadmin'
        ? ['superadmin', 'super administrador', 'super_admin', 'super-admin']
        : ['administrador', 'admin'];
    $placeholders = [];
    $params = [':except_id' => $exceptUserId];
    foreach ($aliases as $index => $alias) {
        $placeholder = ':role' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $alias;
    }

    $stmt = $db->prepare('SELECT `id_usuario` FROM `usuario` WHERE LOWER(TRIM(`rol`)) IN (' . implode(', ', $placeholders) . ') AND `id_usuario` != :except_id LIMIT 1');
    $stmt->execute($params);
    return (bool)$stmt->fetchColumn();
}

function isSuperAdminRole(string $role): bool
{
    return in_array(strtolower(trim($role)), ['superadmin', 'super administrador', 'super_admin', 'super-admin'], true);
}

function generateTemporaryPassword(): string
{
    $characterGroups = [
        'abcdefghijklmnopqrstuvwxyz',
        'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        '0123456789',
        '$',
        '%',
        '+-*/=<>',
        '!@#&()[]{}:;_.,~^|\\'
    ];
    $characters = '';

    foreach ($characterGroups as $group) {
        $characters .= $group[random_int(0, strlen($group) - 1)];
    }

    $allCharacters = implode('', $characterGroups);
    while (strlen($characters) < 32) {
        $characters .= $allCharacters[random_int(0, strlen($allCharacters) - 1)];
    }

    $password = str_split($characters);
    for ($index = count($password) - 1; $index > 0; $index--) {
        $swapIndex = random_int(0, $index);
        [$password[$index], $password[$swapIndex]] = [$password[$swapIndex], $password[$index]];
    }

    return implode('', $password);
}

// Valida los requisitos de contraseña que se aplican al alta y al cambio de credenciales.
function passwordMeetsPolicy(string $password): bool
{
    return strlen($password) >= 26
        && strlen($password) <= 64
        && preg_match('/\A[\x21-\x7E]+\z/', $password) === 1
        && preg_match('/[a-z]/', $password) === 1
        && preg_match('/[A-Z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1
        && preg_match('/[$]/', $password) === 1
        && preg_match('/%/', $password) === 1
        && preg_match('/[+\-*\/=<>]/', $password) === 1
        && preg_match('/[!@#&()\[\]{}:;_.,~^|\\\\]/', $password) === 1;
}

function canManageUserTarget(array $target, ?array $currentUser): bool
{
    if (isSuperAdminApi()) {
        return true;
    }

    if (isSuperAdminRole((string)$target['rol'])) {
        return false;
    }

    return (int)$target['id_usuario'] === (int)($currentUser['id'] ?? 0)
        || strtolower(trim((string)$target['rol'])) === 'vendedor';
}

// GET: Listar usuarios con métricas de ventas, logins e interacciones
if ($method === 'GET') {
    // El detalle privado es exclusivo del Super Admin; el listado devuelve métricas resumidas.
    $action = $_GET['action'] ?? 'list';

    // Si se solicita el detalle de interacción y logins de un usuario específico
    if ($action === 'detail') {
        requireSuperAdminApi();
        $targetUser = trim((string)($_GET['usuario'] ?? ''));
        if ($targetUser === '') {
            sendJson(['ok' => false, 'message' => 'Usuario no especificado.'], 400);
        }

        try {
            // Obtener datos del usuario
            $uStmt = $db->prepare("SELECT `id_usuario`, `nombre`, `rol`, `ultimo_acceso`, `fecha_creacion` FROM `usuario` WHERE LOWER(`nombre`) = LOWER(:nombre) LIMIT 1");
            $uStmt->execute([':nombre' => $targetUser]);
            $userData = $uStmt->fetch();

            if (!$userData) {
                sendJson(['ok' => false, 'message' => 'Usuario no encontrado.'], 404);
            }

            // Ventas realizadas por este usuario
            $vStmt = $db->prepare("
                SELECT 
                    f.`ID_factura`,
                    f.`ID_stock`,
                    f.`fecha`,
                    f.`cantidadVendida`,
                    f.`precioFinal`,
                    f.`ganancia`,
                    COALESCE(NULLIF(f.`nombre_producto`, ''), p.`nombre`, 'Nombre no disponible') AS nombre_producto,
                    COALESCE(p.`codigo`, '---') AS codigo_producto
                FROM (
                    SELECT `ID_factura`, `ID_stock`, `fecha`, `cantidadVendida`, `precioFinal`, `ganancia`, `nombre_producto`, `usuario` FROM `ventas`
                    UNION ALL
                    SELECT `ID_factura`, `ID_stock`, `fecha`, `cantidadVendida`, `precioFinal`, `ganancia`, `nombre_producto`, `usuario` FROM `ventas_historial`
                ) f
                LEFT JOIN `producto` p ON f.`ID_stock` = p.`ID_stock`
                WHERE LOWER(f.`usuario`) = LOWER(:nombre)
                ORDER BY f.`ID_factura` DESC
                LIMIT 100
            ");
            $vStmt->execute([':nombre' => $targetUser]);
            $ventas = $vStmt->fetchAll();

            $totFacturado = 0;
            $totItems = 0;
            foreach ($ventas as $v) {
                $totFacturado += (float)($v['ganancia'] ?? 0);
                $totItems += (int)($v['cantidadVendida'] ?? 0);
            }

            // Actividades generales registradas para este usuario
            $aStmt = $db->prepare("
                SELECT `id_actividad`, `tipo_accion`, `descripcion`, `detalles`, `fecha`
                FROM `actividad_usuario`
                WHERE LOWER(`usuario`) = LOWER(:nombre)
                ORDER BY `id_actividad` DESC
                LIMIT 100
            ");
            $aStmt->execute([':nombre' => $targetUser]);
            $actividades = $aStmt->fetchAll();

            // Inicios de sesión / logins específicos para este usuario
            $lStmt = $db->prepare("
                SELECT `id_actividad`, `tipo_accion`, `descripcion`, `detalles`, `fecha`
                FROM `actividad_usuario`
                WHERE LOWER(`usuario`) = LOWER(:nombre) AND (`tipo_accion` LIKE 'login%' OR `tipo_accion` LIKE 'registro%')
                ORDER BY `id_actividad` DESC
                LIMIT 50
            ");
            $lStmt->execute([':nombre' => $targetUser]);
            $logins = $lStmt->fetchAll();

            sendJson([
                'ok'          => true,
                'usuario'     => [
                    'id'             => (int)$userData['id_usuario'],
                    'nombre'         => (string)$userData['nombre'],
                    'rol'            => (string)$userData['rol'],
                    'ultimo_acceso'  => $userData['ultimo_acceso'] ? date('c', strtotime((string)$userData['ultimo_acceso'])) : null,
                    'fecha_creacion' => $userData['fecha_creacion'] ? date('c', strtotime((string)$userData['fecha_creacion'])) : null,
                ],
                'metricas'    => [
                    'total_ventas'      => count($ventas),
                    'total_facturado'   => $totFacturado,
                    'total_items'       => $totItems,
                    'ticket_promedio'   => count($ventas) > 0 ? round($totFacturado / count($ventas), 2) : 0,
                    'total_actividades' => count($actividades),
                    'total_logins'      => count($logins)
                ],
                'ventas'      => $ventas,
                'actividades' => array_map(function($a) {
                    $det = null;
                    if (!empty($a['detalles'])) {
                        $dec = json_decode((string)$a['detalles'], true);
                        $det = is_array($dec) ? $dec : $a['detalles'];
                    }
                    return [
                        'id'          => (int)$a['id_actividad'],
                        'tipo'        => (string)$a['tipo_accion'],
                        'descripcion' => (string)$a['descripcion'],
                        'detalles'    => $det,
                        'fecha'       => date('c', strtotime((string)$a['fecha']))
                    ];
                }, $actividades),
                'logins'      => array_map(function($l) {
                    $det = null;
                    if (!empty($l['detalles'])) {
                        $dec = json_decode((string)$l['detalles'], true);
                        $det = is_array($dec) ? $dec : $l['detalles'];
                    }
                    return [
                        'id'          => (int)$l['id_actividad'],
                        'tipo'        => (string)$l['tipo_accion'],
                        'descripcion' => (string)$l['descripcion'],
                        'ip'          => is_array($det) ? ($det['ip'] ?? '127.0.0.1') : '127.0.0.1',
                        'user_agent'  => is_array($det) ? ($det['user_agent'] ?? 'Navegador Web') : 'Navegador Web',
                        'detalles'    => $det,
                        'fecha'       => date('c', strtotime((string)$l['fecha']))
                    ];
                }, $logins)
            ]);
        } catch (Exception $e) {
            sendJson(['ok' => false, 'message' => 'Error al consultar detalle de usuario: ' . $e->getMessage()], 500);
        }
    }

    // Listado general de usuarios (con métricas de ventas, logins e interacciones)
    try {
        $userVisibility = isSuperAdminApi()
            ? ''
            : 'WHERE LOWER(TRIM(u.`rol`)) = \'vendedor\' OR u.`id_usuario` = :current_user_id';
        $stmt = $db->prepare("
            SELECT 
                u.`id_usuario`,
                u.`nombre` AS usuario,
                u.`rol` AS role,
                u.`ultimo_acceso`,
                u.`fecha_creacion`,
                COALESCE(COUNT(DISTINCT f.`ID_factura`), 0) AS total_ventas,
                COALESCE(SUM(f.`ganancia`), 0) AS total_facturado,
                (SELECT COUNT(*) FROM `actividad_usuario` a WHERE LOWER(a.`usuario`) = LOWER(u.`nombre`)) AS total_actividades,
                (SELECT COUNT(*) FROM `actividad_usuario` a WHERE LOWER(a.`usuario`) = LOWER(u.`nombre`) AND a.`tipo_accion` LIKE 'login%') AS total_logins
            FROM `usuario` u
            LEFT JOIN (
                SELECT `ID_factura`, `usuario`, `ganancia` FROM `ventas`
                UNION ALL
                SELECT `ID_factura`, `usuario`, `ganancia` FROM `ventas_historial`
            ) f ON LOWER(f.`usuario`) = LOWER(u.`nombre`)
            {$userVisibility}
            GROUP BY u.`id_usuario`, u.`nombre`, u.`rol`, u.`ultimo_acceso`, u.`fecha_creacion`
            ORDER BY u.`id_usuario` ASC
        ");
        if (!isSuperAdminApi()) {
            $stmt->bindValue(':current_user_id', (int)($currentUser['id'] ?? 0), PDO::PARAM_INT);
        }
        $stmt->execute();
        $usersDb = $stmt->fetchAll();

        $result = [];
        $includeLoginMetrics = isSuperAdminApi();
        foreach ($usersDb as $u) {
            $user = [
                'id'                => (int)$u['id_usuario'],
                'usuario'           => (string)$u['usuario'],
                'role'              => (string)($u['role'] ?? 'vendedor'),
                'fecha_creacion'    => $u['fecha_creacion'] ? date('c', strtotime((string)$u['fecha_creacion'])) : null,
                'total_ventas'      => (int)$u['total_ventas'],
                'total_facturado'   => (float)$u['total_facturado'],
                'total_actividades' => (int)$u['total_actividades']
            ];
            if ($includeLoginMetrics) {
                $user['ultimo_acceso'] = $u['ultimo_acceso'] ? date('c', strtotime((string)$u['ultimo_acceso'])) : null;
                $user['total_logins'] = (int)$u['total_logins'];
            }
            $result[] = $user;
        }

        sendJson($result);
    } catch (Exception $e) {
        error_log('Error al consultar usuarios: ' . $e->getMessage());
        sendJson(['ok' => false, 'error' => 'query_error', 'message' => 'No se pudieron consultar los usuarios.'], 500);
    }
}

// POST: Crear nuevo usuario desde la consola del Super Admin
if ($method === 'POST') {
    // Crea una cuenta autorizada y devuelve la contraseña temporal una sola vez al panel.
    $body = getJsonBody();
    $usuario = trim((string) ($body['usuario'] ?? ''));
    $role = trim((string) ($body['role'] ?? 'vendedor'));

    if ($usuario === '') {
        sendJson(['ok' => false, 'error' => 'missing_fields', 'message' => 'Ingresá el nombre de usuario.'], 400);
    }

    $validRoles = ['superadmin', 'administrador', 'vendedor'];
    if (!in_array(strtolower($role), $validRoles, true)) {
        sendJson(['ok' => false, 'error' => 'invalid_role', 'message' => 'El rol seleccionado no es válido.'], 400);
    }
    $role = strtolower($role);

    if ($role === 'superadmin' && !isSuperAdminApi()) {
        sendJson(['ok' => false, 'error' => 'forbidden', 'message' => 'Solo el Super Administrador puede asignar ese rol.'], 403);
    }

    try {
        // Comprobar si ya existe en MySQL
        $checkStmt = $db->prepare("SELECT `id_usuario` FROM `usuario` WHERE LOWER(`nombre`) = LOWER(:nombre) LIMIT 1");
        $checkStmt->execute([':nombre' => $usuario]);
        if ($checkStmt->fetch()) {
            sendJson(['ok' => false, 'error' => 'user_exists', 'message' => 'Ese nombre de usuario ya está registrado.'], 409);
        }

        if ($role !== 'vendedor' && roleHasOccupant($db, $role)) {
            $roleLabel = $role === 'superadmin' ? 'Super Administrador' : 'Administrador';
            sendJson(['ok' => false, 'error' => 'role_limit_reached', 'message' => "Ya existe una cuenta con el rol {$roleLabel}. No se puede asignar a otro usuario."], 409);
        }

        $temporaryPassword = generateTemporaryPassword();
        $db->beginTransaction();
        $insertStmt = $db->prepare("INSERT INTO `usuario` (`nombre`, `password`, `rol`, `fecha_creacion`) VALUES (:nombre, :pass, :rol, NOW())");
        $insertStmt->execute([
            ':nombre' => $usuario,
            ':pass'   => password_hash($temporaryPassword, PASSWORD_DEFAULT),
            ':rol'    => $role
        ]);
        $newUserId = (int)$db->lastInsertId();

        $creatorName = (string)($currentUser['usuario'] ?? 'Administrador');
        $activityStmt = $db->prepare("
            INSERT INTO `actividad_usuario` (`id_usuario`, `usuario`, `tipo_accion`, `descripcion`, `detalles`, `fecha`)
            VALUES (:id_usuario, :usuario, 'usuario_creado', :descripcion, :detalles, NOW())
        ");
        $activityStmt->execute([
            ':id_usuario' => (int)($currentUser['id'] ?? 0) ?: null,
            ':usuario' => $creatorName,
            ':descripcion' => "Usuario '{$usuario}' creado con rol '{$role}'.",
            ':detalles' => json_encode(['usuario' => $usuario, 'rol' => $role, 'creado_por' => $creatorName], JSON_UNESCAPED_UNICODE)
        ]);
        $db->commit();

        sendJson([
            'ok'      => true,
            'message' => "Usuario '{$usuario}' creado con rol '{$role}'.",
            'user'    => [
                'id'      => $newUserId,
                'usuario' => $usuario,
                'role'    => $role,
            ],
            'temporary_password' => $temporaryPassword
        ]);
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($e->getCode() === '23000') {
            $duplicateUser = $db->prepare("SELECT 1 FROM `usuario` WHERE LOWER(`nombre`) = LOWER(:nombre) LIMIT 1");
            $duplicateUser->execute([':nombre' => $usuario]);
            if ($duplicateUser->fetchColumn()) {
                sendJson(['ok' => false, 'error' => 'user_exists', 'message' => 'Ese nombre de usuario ya está registrado.'], 409);
            }
            sendJson(['ok' => false, 'error' => 'role_limit_reached', 'message' => 'Ese rol administrativo ya está asignado a otra cuenta.'], 409);
        }
        sendJson(['ok' => false, 'error' => 'save_error', 'message' => 'Error al guardar usuario: ' . $e->getMessage()], 500);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        sendJson(['ok' => false, 'error' => 'save_error', 'message' => 'Error al guardar usuario: ' . $e->getMessage()], 500);
    }
}

// PUT: Modificar usuario o cambiar su rol (Solo Super Admin)
if ($method === 'PUT') {
    // Actualiza identidad, rol o contraseña comprobando permisos sobre la cuenta destino.
    $body = getJsonBody();
    $id = isset($body['id']) ? (int)$body['id'] : null;
    $usuario = trim((string)($body['usuario'] ?? ''));
    $newRole = isset($body['role']) ? trim((string)$body['role']) : null;
    $newPassword = isset($body['password']) && trim((string)$body['password']) !== '' ? (string)$body['password'] : null;

    if ($newPassword !== null && !passwordMeetsPolicy($newPassword)) {
        sendJson([
            'ok' => false,
            'error' => 'weak_password',
            'message' => 'La contraseña debe tener entre 26 y 64 caracteres ASCII imprimibles e incluir minúsculas, mayúsculas, números, $, %, un operador (+ - * / = < >) y otro símbolo.'
        ], 400);
    }

    if (!$id && $usuario === '') {
        sendJson(['ok' => false, 'error' => 'missing_params', 'message' => 'Se requiere el ID o nombre del usuario.'], 400);
    }

    try {
        // Buscar usuario en DB
        $findStmt = $db->prepare("SELECT `id_usuario`, `nombre`, `rol` FROM `usuario` WHERE `id_usuario` = :id OR LOWER(`nombre`) = LOWER(:nombre) LIMIT 1");
        $findStmt->execute([
            ':id'     => $id ?? 0,
            ':nombre' => $usuario
        ]);
        $target = $findStmt->fetch();

        if (!$target) {
            sendJson(['ok' => false, 'error' => 'not_found', 'message' => 'Usuario no encontrado.'], 404);
        }

        if (!canManageUserTarget($target, $currentUser)) {
            sendJson(['ok' => false, 'error' => 'not_found', 'message' => 'Usuario no encontrado.'], 404);
        }

        $targetId = (int)$target['id_usuario'];
        $targetName = (string)$target['nombre'];
        $currentRole = (string)$target['rol'];
        $superAdminName = $currentUser['usuario'] ?? 'Super Admin';

        // Protección: Si el Super Admin intenta cambiarse de rol a sí mismo, verificar que quede al menos otro Super Admin
        if ($newPassword !== null) {
            logActivity(
                $superAdminName,
                'password_restablecida',
                "El Super Admin {$superAdminName} restableció la contraseña del usuario '{$targetName}'",
                ['usuario' => $targetName],
                $targetId
            );
        } elseif ($newRole !== null && strcasecmp($newRole, $currentRole) !== 0) {
            if ($targetId === (int)($currentUser['id'] ?? 0) && strtolower($newRole) !== 'superadmin') {
                $countStmt = $db->prepare("SELECT COUNT(*) FROM `usuario` WHERE `rol` = 'superadmin' AND `id_usuario` != :id");
                $countStmt->execute([':id' => $targetId]);
                $otherSuperAdmins = (int)$countStmt->fetchColumn();
                if ($otherSuperAdmins === 0) {
                    sendJson(['ok' => false, 'error' => 'forbidden', 'message' => 'No podés remover tus permisos de Super Administrador porque sos el único Super Admin del sistema.'], 403);
                }
            }
        }

        // Construir actualización dinámica
        $updates = [];
        $params = [':id' => $targetId];
        $nombreFinal = $targetName;

        if ($usuario !== '' && strcasecmp($usuario, $targetName) !== 0) {
            $dupStmt = $db->prepare("SELECT `id_usuario` FROM `usuario` WHERE LOWER(`nombre`) = LOWER(:nombre) AND `id_usuario` != :id LIMIT 1");
            $dupStmt->execute([':nombre' => $usuario, ':id' => $targetId]);
            if ($dupStmt->fetch()) {
                sendJson(['ok' => false, 'error' => 'user_exists', 'message' => 'Ese nombre de usuario ya está registrado.'], 409);
            }
            $updates[] = "`nombre` = :nombre";
            $params[':nombre'] = $usuario;
            $nombreFinal = $usuario;
        }

        if ($newRole !== null) {
            $roleClean = strtolower(trim($newRole));
            if (in_array($roleClean, ['superadmin', 'super administrador', 'super_admin'], true)) {
                $roleClean = 'superadmin';
            } elseif (in_array($roleClean, ['administrador', 'admin'], true)) {
                $roleClean = 'administrador';
            } else {
                $roleClean = 'vendedor';
            }
            if ($roleClean === 'superadmin' && !isSuperAdminApi()) {
                sendJson(['ok' => false, 'error' => 'forbidden', 'message' => 'Solo el Super Administrador puede asignar ese rol.'], 403);
            }
            $updates[] = "`rol` = :rol";
            $params[':rol'] = $roleClean;
            $newRole = $roleClean;
        }

        if ($newRole !== null && $newRole !== 'vendedor' && strtolower(trim($currentRole)) !== $newRole
            && roleHasOccupant($db, $newRole, $targetId)) {
            $roleLabel = $newRole === 'superadmin' ? 'Super Administrador' : 'Administrador';
            sendJson(['ok' => false, 'error' => 'role_limit_reached', 'message' => "Ya existe una cuenta con el rol {$roleLabel}. Primero cambiá ese rol a vendedor."], 409);
        }

        if ($newPassword !== null) {
            $updates[] = "`password` = :pass";
            $params[':pass'] = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        if (empty($updates)) {
            sendJson(['ok' => true, 'message' => 'No hubo cambios para actualizar.']);
        }

        $sql = "UPDATE `usuario` SET " . implode(', ', $updates) . " WHERE `id_usuario` = :id";
        $updStmt = $db->prepare($sql);
        $updStmt->execute($params);

        if ($newRole !== null && strcasecmp($newRole, $currentRole) !== 0) {
            logActivity(
                $superAdminName,
                'rol_cambiado',
                "El Super Admin {$superAdminName} cambió el rol de '{$targetName}' de '{$currentRole}' a '{$newRole}'",
                ['usuario' => $targetName, 'rol_anterior' => $currentRole, 'nuevo_rol' => $newRole],
                $targetId
            );
        } else {
            logActivity(
                $superAdminName,
                'usuario_modificado',
                "Datos del usuario '{$targetName}' actualizados por el Super Admin {$superAdminName}",
                ['usuario' => $targetName],
                $targetId
            );
        }

        sendJson([
            'ok'      => true,
            'message' => "Usuario '{$nombreFinal}' actualizado correctamente.",
            'user'    => [
                'id'      => $targetId,
                'usuario' => $nombreFinal,
                'role'    => $newRole ?? $currentRole
            ]
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            sendJson(['ok' => false, 'error' => 'role_limit_reached', 'message' => 'Ese rol administrativo ya está asignado a otra cuenta.'], 409);
        }
        sendJson(['ok' => false, 'error' => 'update_error', 'message' => 'Error al actualizar usuario: ' . $e->getMessage()], 500);
    } catch (Exception $e) {
        sendJson(['ok' => false, 'error' => 'update_error', 'message' => 'Error al actualizar usuario: ' . $e->getMessage()], 500);
    }
}

// DELETE: Eliminar usuario (Solo Super Admin)
if ($method === 'DELETE') {
    // Impide que se eliminen cuentas protegidas y registra el cambio en la auditoría.
    $id = $_GET['id'] ?? null;
    if (!$id) {
        $body = getJsonBody();
        $id = $body['id'] ?? null;
    }

    if (!$id) {
        sendJson(['ok' => false, 'error' => 'missing_id', 'message' => 'Se requiere el ID del usuario a eliminar.'], 400);
    }

    $idInt = (int)$id;

    // Protección: Un Super Admin no puede eliminarse a sí mismo
    if ($idInt === (int)($currentUser['id'] ?? 0)) {
        sendJson(['ok' => false, 'error' => 'self_delete', 'message' => 'No podés eliminar tu propia cuenta de Super Admin mientras tenés la sesión activa.'], 403);
    }

    try {
        $findStmt = $db->prepare("SELECT `id_usuario`, `nombre`, `rol` FROM `usuario` WHERE `id_usuario` = :id LIMIT 1");
        $findStmt->execute([':id' => $idInt]);
        $target = $findStmt->fetch();

        if (!$target) {
            sendJson(['ok' => false, 'error' => 'not_found', 'message' => 'Usuario no encontrado.'], 404);
        }

        if (!canManageUserTarget($target, $currentUser)) {
            sendJson(['ok' => false, 'error' => 'not_found', 'message' => 'Usuario no encontrado.'], 404);
        }

        $targetName = (string)$target['nombre'];

        // Eliminar en MySQL
        $delStmt = $db->prepare("DELETE FROM `usuario` WHERE `id_usuario` = :id");
        $delStmt->execute([':id' => $idInt]);

        $superAdminName = $currentUser['usuario'] ?? 'Super Admin';
        logActivity(
            $superAdminName,
            'usuario_eliminado',
            "Usuario '{$targetName}' eliminado del sistema por el Super Admin {$superAdminName}",
            ['id_usuario' => $idInt, 'usuario' => $targetName],
            $currentUser['id'] ?? null
        );

        sendJson([
            'ok'      => true,
            'message' => "Usuario '{$targetName}' eliminado correctamente."
        ]);
    } catch (Exception $e) {
        sendJson(['ok' => false, 'error' => 'delete_error', 'message' => 'Error al eliminar usuario: ' . $e->getMessage()], 500);
    }
}

sendJson(['error' => 'method_not_allowed'], 405);
