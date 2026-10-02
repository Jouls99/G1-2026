<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

handleOptions();
requireAdminApi();

$method = requestMethod();
$db = getDBConnection();
$file = dataPath('users.json');

if ($method === 'GET') {
    if ($db !== null) {
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
            // Continuar con los usuarios del respaldo JSON.
        }
    }

    $users = array_values(array_filter(readJsonFile($file), static function (array $user): bool {
        return strtolower((string)($user['role'] ?? 'vendedor')) === 'vendedor';
    }));
    $users = array_map(static function (array $user): array {
        return [
            'id' => (int)($user['id'] ?? 0),
            'usuario' => (string)($user['usuario'] ?? ''),
            'puede_registrar_stock' => !empty($user['puede_registrar_stock']),
            'puede_modificar_informes' => !empty($user['puede_modificar_informes'])
        ];
    }, $users);
    sendJson(['ok' => true, 'usuarios' => $users]);
}

if ($method === 'PUT') {
    $body = getJsonBody() ?? [];
    $id = (int)($body['id'] ?? 0);
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

    $targetName = '';
    if ($db !== null) {
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
            $targetName = '';
        }
    }

    $users = readJsonFile($file);
    $found = false;
    foreach ($users as &$user) {
        $sameId = (int)($user['id'] ?? 0) === $id;
        $sameName = $targetName !== '' && strcasecmp((string)($user['usuario'] ?? ''), $targetName) === 0;
        if (($sameId || $sameName) && strtolower((string)($user['role'] ?? 'vendedor')) === 'vendedor') {
            foreach ($updates as $permission => $enabled) {
                $user[$permission] = $enabled;
            }
            $targetName = (string)($user['usuario'] ?? $targetName);
            $found = true;
            break;
        }
    }
    unset($user);

    if ($db === null && !$found) {
        sendJson(['ok' => false, 'message' => 'Vendedor no encontrado.'], 404);
    }
    writeJsonFile($file, $users);

    $actor = getApiUser();
    $changedBy = (string)($actor['usuario'] ?? 'Administrador');
    logActivity(
        $changedBy,
        'permisos_vendedor_actualizados',
        "Permisos del vendedor '{$targetName}' actualizados por {$changedBy}",
        ['vendedor' => $targetName, 'permisos' => $updates],
        isset($actor['id']) ? (int)$actor['id'] : null
    );

    sendJson(['ok' => true, 'message' => 'Permisos actualizados correctamente.']);
}

sendJson(['ok' => false, 'message' => 'Método no permitido.'], 405);