<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

handleOptions();

$file = dataPath('users.json');
$method = requestMethod();
$db = getDBConnection();
$currentUser = getApiUser();

// GET: Listar usuarios con métricas de ventas e interacciones
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
    
    // Si se solicita el detalle de interacción específico de un usuario
    if ($action === 'detail') {
        requireAdminApi();
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
                    f.`fecha`,
                    f.`cantidadVendida`,
                    f.`precioFinal`,
                    f.`ganancia`,
                    COALESCE(p.`nombre`, 'Producto') AS nombre_producto,
                    COALESCE(p.`codigo`, '---') AS codigo_producto
                FROM `facturacion` f
                LEFT JOIN `producto` p ON f.`ID_stock` = p.`ID_stock`
                WHERE LOWER(f.`usuario`) = LOWER(:nombre)
                ORDER BY f.`ID_factura` DESC
                LIMIT 100
            ");
            $vStmt->execute([':nombre' => $targetUser]);
            $ventas = $vStmt->fetchAll();

            // Total facturado y cantidad de ventas
            $totFacturado = 0;
            $totItems = 0;
            foreach ($ventas as $v) {
                $totFacturado += (float)($v['ganancia'] ?? 0);
                $totItems += (int)($v['cantidadVendida'] ?? 0);
            }

            // Actividades registradas para este usuario
            $aStmt = $db->prepare("
                SELECT `id_actividad`, `tipo_accion`, `descripcion`, `detalles`, `fecha`
                FROM `actividad_usuario`
                WHERE LOWER(`usuario`) = LOWER(:nombre)
                ORDER BY `id_actividad` DESC
                LIMIT 100
            ");
            $aStmt->execute([':nombre' => $targetUser]);
            $actividades = $aStmt->fetchAll();

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
                    'total_ventas'    => count($ventas),
                    'total_facturado' => $totFacturado,
                    'total_items'     => $totItems,
                    'ticket_promedio' => count($ventas) > 0 ? round($totFacturado / count($ventas), 2) : 0,
                    'total_actividades' => count($actividades)
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
                }, $actividades)
            ]);
        } catch (Exception $e) {
            sendJson(['ok' => false, 'message' => 'Error al consultar detalle de usuario: ' . $e->getMessage()], 500);
        }
    }

    // Listado general de usuarios (con métricas si es admin)
    try {
        $stmt = $db->query("
            SELECT 
                u.`id_usuario`,
                u.`nombre` AS usuario,
                u.`rol` AS role,
                u.`ultimo_acceso`,
                u.`fecha_creacion`,
                COALESCE(COUNT(DISTINCT f.`ID_factura`), 0) AS total_ventas,
                COALESCE(SUM(f.`ganancia`), 0) AS total_facturado,
                (SELECT COUNT(*) FROM `actividad_usuario` a WHERE LOWER(a.`usuario`) = LOWER(u.`nombre`)) AS total_actividades
            FROM `usuario` u
            LEFT JOIN `facturacion` f ON LOWER(f.`usuario`) = LOWER(u.`nombre`)
            GROUP BY u.`id_usuario`, u.`nombre`, u.`rol`, u.`ultimo_acceso`, u.`fecha_creacion`
            ORDER BY u.`id_usuario` ASC
        ");
        $usersDb = $stmt->fetchAll();

        $result = [];
        foreach ($usersDb as $u) {
            $result[] = [
                'id'                => (int)$u['id_usuario'],
                'usuario'           => (string)$u['usuario'],
                'role'              => (string)($u['role'] ?? 'vendedor'),
                'ultimo_acceso'     => $u['ultimo_acceso'] ? date('c', strtotime((string)$u['ultimo_acceso'])) : null,
                'fecha_creacion'    => $u['fecha_creacion'] ? date('c', strtotime((string)$u['fecha_creacion'])) : null,
                'total_ventas'      => (int)$u['total_ventas'],
                'total_facturado'   => (float)$u['total_facturado'],
                'total_actividades' => (int)$u['total_actividades']
            ];
        }

        sendJson($result);
    } catch (Exception $e) {
        // Fallback a JSON
        $users = readJsonFile($file);
        $safeUsers = array_map(function ($u) {
            return [
                'id'                => $u['id'] ?? 1,
                'usuario'           => $u['usuario'] ?? '',
                'role'              => $u['role'] ?? 'vendedor',
                'ultimo_acceso'     => $u['lastLogin'] ?? null,
                'fecha_creacion'    => $u['createdAt'] ?? null,
                'total_ventas'      => 0,
                'total_facturado'   => 0,
                'total_actividades' => 0
            ];
        }, $users);
        sendJson($safeUsers);
    }
}

// POST: Crear nuevo usuario desde el panel o registro
if ($method === 'POST') {
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

    // Si intenta asignar rol de administrador, debe ser admin
    if (in_array(strtolower($role), ['administrador', 'admin'], true) && !isAdminApi()) {
        $role = 'vendedor'; // Fallback a vendedor si no es admin
    }

    try {
        // Comprobar si ya existe en MySQL
        $checkStmt = $db->prepare("SELECT `id_usuario` FROM `usuario` WHERE LOWER(`nombre`) = LOWER(:nombre) LIMIT 1");
        $checkStmt->execute([':nombre' => $usuario]);
        if ($checkStmt->fetch()) {
            sendJson(['ok' => false, 'error' => 'user_exists', 'message' => 'Ese nombre de usuario ya está registrado.'], 409);
        }

        $insertStmt = $db->prepare("INSERT INTO `usuario` (`nombre`, `contraseña`, `rol`, `fecha_creacion`) VALUES (:nombre, :pass, :rol, NOW())");
        $insertStmt->execute([
            ':nombre' => $usuario,
            ':pass'   => $password,
            ':rol'    => $role
        ]);
        $newUserId = (int)$db->lastInsertId();

        // Sincronizar archivo JSON como respaldo
        $users = readJsonFile($file);
        $users[] = [
            'id'        => $newUserId,
            'usuario'   => $usuario,
            'password'  => $password,
            'role'      => $role,
            'createdAt' => date('c')
        ];
        writeJsonFile($file, $users);

        $adminName = $currentUser['usuario'] ?? $usuario;
        logActivity(
            $adminName,
            'usuario_creado',
            "Usuario '{$usuario}' creado exitosamente con rol '{$role}' por {$adminName}",
            ['usuario' => $usuario, 'rol' => $role],
            $newUserId
        );

        sendJson([
            'ok'      => true,
            'message' => "Usuario '{$usuario}' registrado con éxito.",
            'user'    => [
                'id'      => $newUserId,
                'usuario' => $usuario,
                'role'    => $role
            ]
        ]);
    } catch (Exception $e) {
        sendJson(['ok' => false, 'error' => 'save_error', 'message' => 'Error al guardar usuario: ' . $e->getMessage()], 500);
    }
}

// PUT: Modificar usuario o cambiar su rol (Solo Administradores)
if ($method === 'PUT') {
    requireAdminApi();

    $body = getJsonBody();
    $id = isset($body['id']) ? (int)$body['id'] : null;
    $usuario = trim((string)($body['usuario'] ?? ''));
    $newRole = isset($body['role']) ? trim((string)$body['role']) : null;
    $newPassword = isset($body['password']) && trim((string)$body['password']) !== '' ? (string)$body['password'] : null;

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

        $targetId = (int)$target['id_usuario'];
        $targetName = (string)$target['nombre'];
        $currentRole = (string)$target['rol'];

        // Protección: Si el admin intenta quitarse el rol de admin a sí mismo, verificar que quede al menos otro admin
        if ($newRole !== null && strcasecmp($newRole, $currentRole) !== 0) {
            if ($targetId === (int)($currentUser['id'] ?? 0) && strtolower($newRole) !== 'administrador') {
                $otherAdmins = (int)$db->query("SELECT COUNT(*) FROM `usuario` WHERE `rol` = 'administrador' AND `id_usuario` != {$targetId}")->fetchColumn();
                if ($otherAdmins === 0) {
                    sendJson(['ok' => false, 'error' => 'forbidden', 'message' => 'No podés remover tus permisos de Administrador porque sos el único administrador del sistema.'], 403);
                }
            }
        }

        // Construir actualización dinámica
        $updates = [];
        $params = [':id' => $targetId];

        if ($newRole !== null && in_array(strtolower($newRole), ['administrador', 'vendedor', 'admin'], true)) {
            $updates[] = "`rol` = :rol";
            $params[':rol'] = $newRole;
        }

        if ($newPassword !== null) {
            $updates[] = "`contraseña` = :pass";
            $params[':pass'] = $newPassword;
        }

        if (empty($updates)) {
            sendJson(['ok' => true, 'message' => 'No hubo cambios para actualizar.']);
        }

        $sql = "UPDATE `usuario` SET " . implode(', ', $updates) . " WHERE `id_usuario` = :id";
        $updStmt = $db->prepare($sql);
        $updStmt->execute($params);

        // Sincronizar en JSON
        $users = readJsonFile($file);
        foreach ($users as &$u) {
            if ((isset($u['id']) && (int)$u['id'] === $targetId) || strcasecmp((string)($u['usuario'] ?? ''), $targetName) === 0) {
                if ($newRole !== null) {
                    $u['role'] = $newRole;
                }
                if ($newPassword !== null) {
                    $u['password'] = $newPassword;
                }
            }
        }
        writeJsonFile($file, $users);

        // Si se cambió el rol, registrar interacción específica
        $adminName = $currentUser['usuario'] ?? 'Administrador';
        if ($newRole !== null && strcasecmp($newRole, $currentRole) !== 0) {
            logActivity(
                $adminName,
                'rol_cambiado',
                "El Administrador {$adminName} cambió el rol de '{$targetName}' de '{$currentRole}' a '{$newRole}'",
                ['usuario' => $targetName, 'rol_anterior' => $currentRole, 'nuevo_rol' => $newRole],
                $targetId
            );
        } else {
            logActivity(
                $adminName,
                'usuario_modificado',
                "Datos del usuario '{$targetName}' modificados por {$adminName}",
                ['usuario' => $targetName],
                $targetId
            );
        }

        sendJson([
            'ok'      => true,
            'message' => "Usuario '{$targetName}' actualizado correctamente.",
            'user'    => [
                'id'      => $targetId,
                'usuario' => $targetName,
                'role'    => $newRole ?? $currentRole
            ]
        ]);
    } catch (Exception $e) {
        sendJson(['ok' => false, 'error' => 'update_error', 'message' => 'Error al actualizar usuario: ' . $e->getMessage()], 500);
    }
}

// DELETE: Eliminar usuario (Solo Administradores)
if ($method === 'DELETE') {
    requireAdminApi();

    $id = $_GET['id'] ?? null;
    if (!$id) {
        $body = getJsonBody();
        $id = $body['id'] ?? null;
    }

    if (!$id) {
        sendJson(['ok' => false, 'error' => 'missing_id', 'message' => 'Se requiere el ID del usuario a eliminar.'], 400);
    }

    $idInt = (int)$id;

    // Protección: Un administrador no puede eliminarse a sí mismo
    if ($idInt === (int)($currentUser['id'] ?? 0)) {
        sendJson(['ok' => false, 'error' => 'self_delete', 'message' => 'No podés eliminar tu propia cuenta mientras tenés la sesión activa.'], 403);
    }

    try {
        $findStmt = $db->prepare("SELECT `nombre`, `rol` FROM `usuario` WHERE `id_usuario` = :id LIMIT 1");
        $findStmt->execute([':id' => $idInt]);
        $target = $findStmt->fetch();

        if (!$target) {
            sendJson(['ok' => false, 'error' => 'not_found', 'message' => 'Usuario no encontrado.'], 404);
        }

        $targetName = (string)$target['nombre'];

        // Eliminar en MySQL
        $delStmt = $db->prepare("DELETE FROM `usuario` WHERE `id_usuario` = :id");
        $delStmt->execute([':id' => $idInt]);

        // Sincronizar en users.json
        $users = readJsonFile($file);
        $users = array_values(array_filter($users, function ($u) use ($idInt, $targetName) {
            $matchId = isset($u['id']) && (int)$u['id'] === $idInt;
            $matchName = strcasecmp((string)($u['usuario'] ?? ''), $targetName) === 0;
            return !$matchId && !$matchName;
        }));
        writeJsonFile($file, $users);

        $adminName = $currentUser['usuario'] ?? 'Administrador';
        logActivity(
            $adminName,
            'usuario_eliminado',
            "Usuario '{$targetName}' eliminado del sistema por el Administrador {$adminName}",
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
