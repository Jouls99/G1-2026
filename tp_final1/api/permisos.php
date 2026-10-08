<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

handleOptions();
// Toda consulta o modificación de permisos delegables requiere rol administrativo.
requireAdminApi();

$method = requestMethod();
$db = requireApiDatabase();

if ($method === 'GET') {
    // Devuelve únicamente vendedores y normaliza tipos para la tabla del panel administrativo.
    try {
        $stmt = $db->query("SELECT `id_usuario` AS id, `nombre` AS usuario, `puede_registrar_stock`, `puede_modificar_informes` FROM `usuario` WHERE LOWER(`rol`) = 'vendedor' ORDER BY `nombre` ASC");
        $users = array_map(static function (array $user): array {
            return [
                'id' => (int)$user['id'],
                'usuario' => (string)$user['usuario'],
                'puede_registrar_stock' => (bool)$user['puede_registrar_stock'],
                'puede_modificar_informes' => (bool)$user['puede_modificar_informes']
            ];
        }, $stmt->fetchAll());
        sendJson(['ok' => true, 'usuarios' => $users]);
    } catch (Exception $e) {
        error_log('Error al consultar permisos de usuarios: ' . $e->getMessage());
        sendJson(['ok' => false, 'message' => 'No se pudieron consultar los permisos.'], 500);
    }
}

if ($method === 'PUT') {
    // Acepta cambios parciales, verifica que el destino sea vendedor, valida la contraseña del admin y registra quién los hizo.
    $body = getJsonBody() ?? [];
    $id = (int)($body['id'] ?? 0);
    $adminPassword = (string)($body['password'] ?? $body['admin_password'] ?? '');

    if (trim($adminPassword) === '') {
        sendJson(['ok' => false, 'error' => 'password_required', 'message' => 'Ingresá tu contraseña de administrador para confirmar los cambios.'], 400);
    }

    $actor = getApiUser();
    $actorId = (int)($actor['id'] ?? 0);
    $changedBy = (string)($actor['usuario'] ?? 'Administrador');

    if ($actorId <= 0 && $changedBy === '') {
        sendJson(['ok' => false, 'error' => 'unauthorized', 'message' => 'Sesión no válida o expirada.'], 401);
    }

    try {
        $adminStmt = $db->prepare("SELECT `id_usuario`, `password` FROM `usuario` WHERE `id_usuario` = :id OR LOWER(`nombre`) = LOWER(:nombre) LIMIT 1");
        $adminStmt->execute([
            ':id' => $actorId,
            ':nombre' => $changedBy
        ]);
        $adminRow = $adminStmt->fetch();

        if (!$adminRow || !password_verify($adminPassword, (string)($adminRow['password'] ?? ''))) {
            logActivity(
                $changedBy,
                'permisos_delegacion_rechazada',
                "Intento fallido de actualizar permisos de vendedor: contraseña de administrador incorrecta",
                ['id_vendedor' => $id],
                $actorId > 0 ? $actorId : null
            );
            sendJson(['ok' => false, 'error' => 'invalid_password', 'message' => 'La contraseña ingresada es incorrecta.'], 401);
        }
    } catch (Exception $e) {
        error_log('Error al validar contraseña del administrador: ' . $e->getMessage());
        sendJson(['ok' => false, 'message' => 'Error al validar credenciales.'], 500);
    }

    $permissionFields = ['puede_registrar_stock', 'puede_modificar_informes'];
    $updates = [];
    foreach ($permissionFields as $permission) {
        if (array_key_exists($permission, $body)) {
            $updates[$permission] = filter_var($body[$permission], FILTER_VALIDATE_BOOLEAN);
        }
    }

    if ($id < 1 || $updates === []) {
        sendJson(['ok' => false, 'message' => 'Indicá el vendedor y al menos un permiso.'], 400);
    }

    try {
        $find = $db->prepare("SELECT `nombre` FROM `usuario` WHERE `id_usuario` = :id AND LOWER(`rol`) = 'vendedor' LIMIT 1");
        $find->execute([':id' => $id]);
        $targetName = (string)($find->fetchColumn() ?: '');
        if ($targetName === '') {
            sendJson(['ok' => false, 'message' => 'Solo se pueden asignar permisos a vendedores.'], 404);
        }

        $setParts = [];
        $params = [':id' => $id];
        foreach ($updates as $permission => $enabled) {
            $setParts[] = "`{$permission}` = :{$permission}";
            $params[":" . $permission] = $enabled ? 1 : 0;
        }
        $stmt = $db->prepare('UPDATE `usuario` SET ' . implode(', ', $setParts) . ' WHERE `id_usuario` = :id');
        $stmt->execute($params);
    } catch (Exception $e) {
        error_log('Error al actualizar permisos de usuario: ' . $e->getMessage());
        sendJson(['ok' => false, 'message' => 'No se pudieron actualizar los permisos.'], 500);
    }

    logActivity(
        $changedBy,
        'permisos_vendedor_actualizados',
        "Permisos del vendedor '{$targetName}' actualizados por {$changedBy}",
        ['vendedor' => $targetName, 'permisos' => $updates],
        $actorId > 0 ? $actorId : null
    );

    sendJson(['ok' => true, 'message' => "Permisos de '{$targetName}' actualizados correctamente."]);
}

sendJson(['ok' => false, 'message' => 'Método no permitido.'], 405);