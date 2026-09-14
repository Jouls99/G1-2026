<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

handleOptions();

$method = requestMethod();
$currentUser = getApiUser();

if ($method === 'GET') {
    // Solo administradores pueden ver el registro completo de interacciones
    requireAdminApi();

    $db = getDBConnection();
    $filtroUsuario = trim((string)($_GET['usuario'] ?? ''));
    $filtroTipo = trim((string)($_GET['tipo'] ?? ''));
    $limit = min(500, max(1, (int)($_GET['limit'] ?? 150)));

    $actividades = [];
    $stats = [
        'total'  => 0,
        'ventas' => 0,
        'logins' => 0,
        'stock'  => 0,
        'roles'  => 0
    ];

    try {
        $where = [];
        $params = [];

        if ($filtroUsuario !== '' && strtolower($filtroUsuario) !== 'todos') {
            $where[] = "LOWER(a.`usuario`) = LOWER(:usuario)";
            $params[':usuario'] = $filtroUsuario;
        }

        if ($filtroTipo !== '' && strtolower($filtroTipo) !== 'todas') {
            if ($filtroTipo === 'venta') {
                $where[] = "a.`tipo_accion` LIKE 'venta%'";
            } elseif ($filtroTipo === 'login') {
                $where[] = "(a.`tipo_accion` LIKE 'login%' OR a.`tipo_accion` LIKE 'registro%')";
            } elseif ($filtroTipo === 'stock') {
                $where[] = "a.`tipo_accion` LIKE 'stock%'";
            } elseif ($filtroTipo === 'roles' || $filtroTipo === 'rol') {
                $where[] = "(a.`tipo_accion` LIKE '%rol%' OR a.`tipo_accion` LIKE '%usuario%')";
            } else {
                $where[] = "a.`tipo_accion` = :tipo";
                $params[':tipo'] = $filtroTipo;
            }
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $query = "
            SELECT 
                a.`id_actividad`,
                a.`id_usuario`,
                a.`usuario`,
                a.`tipo_accion`,
                a.`descripcion`,
                a.`detalles`,
                a.`fecha`,
                COALESCE(u.`rol`, 'vendedor') AS rol_usuario
            FROM `actividad_usuario` a
            LEFT JOIN `usuario` u ON a.`id_usuario` = u.`id_usuario` OR LOWER(a.`usuario`) = LOWER(u.`nombre`)
            {$whereSql}
            ORDER BY a.`id_actividad` DESC
            LIMIT {$limit}
        ";

        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $detalles = null;
            if (!empty($row['detalles'])) {
                $decoded = json_decode((string)$row['detalles'], true);
                $detalles = is_array($decoded) ? $decoded : $row['detalles'];
            }

            $actividades[] = [
                'id'          => (int)$row['id_actividad'],
                'id_usuario'  => $row['id_usuario'] ? (int)$row['id_usuario'] : null,
                'usuario'     => (string)$row['usuario'],
                'rol'         => (string)$row['rol_usuario'],
                'tipo'        => (string)$row['tipo_accion'],
                'descripcion' => (string)$row['descripcion'],
                'detalles'    => $detalles,
                'fecha'       => date('c', strtotime((string)$row['fecha']))
            ];
        }

        // Estadísticas generales de actividad
        $statsStmt = $db->query("
            SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN `tipo_accion` LIKE 'venta%' THEN 1 ELSE 0 END) AS ventas,
                SUM(CASE WHEN `tipo_accion` LIKE 'login%' OR `tipo_accion` LIKE 'registro%' THEN 1 ELSE 0 END) AS logins,
                SUM(CASE WHEN `tipo_accion` LIKE 'stock%' THEN 1 ELSE 0 END) AS stock,
                SUM(CASE WHEN `tipo_accion` LIKE '%rol%' OR `tipo_accion` LIKE '%usuario%' THEN 1 ELSE 0 END) AS roles
            FROM `actividad_usuario`
        ");
        $statRow = $statsStmt->fetch();
        if ($statRow) {
            $stats = [
                'total'  => (int)$statRow['total'],
                'ventas' => (int)($statRow['ventas'] ?? 0),
                'logins' => (int)($statRow['logins'] ?? 0),
                'stock'  => (int)($statRow['stock'] ?? 0),
                'roles'  => (int)($statRow['roles'] ?? 0),
            ];
        }

        sendJson([
            'ok'          => true,
            'actividades' => $actividades,
            'stats'       => $stats
        ]);
    } catch (Exception $e) {
        // Fallback a JSON
        $jsonFile = dataPath('actividades.json');
        $all = readJsonFile($jsonFile);
        $filtered = array_reverse($all);

        if ($filtroUsuario !== '' && strtolower($filtroUsuario) !== 'todos') {
            $filtered = array_filter($filtered, function ($a) use ($filtroUsuario) {
                return strcasecmp((string)($a['usuario'] ?? ''), $filtroUsuario) === 0;
            });
        }

        sendJson([
            'ok'          => true,
            'actividades' => array_slice(array_values($filtered), 0, $limit),
            'stats'       => [
                'total'  => count($all),
                'ventas' => count(array_filter($all, fn($a) => str_starts_with((string)($a['tipo_accion'] ?? ''), 'venta'))),
                'logins' => count(array_filter($all, fn($a) => str_starts_with((string)($a['tipo_accion'] ?? ''), 'login'))),
                'stock'  => count(array_filter($all, fn($a) => str_starts_with((string)($a['tipo_accion'] ?? ''), 'stock'))),
                'roles'  => count(array_filter($all, fn($a) => str_contains((string)($a['tipo_accion'] ?? ''), 'rol'))),
            ]
        ]);
    }
}

if ($method === 'POST') {
    $body = getJsonBody();
    $usuario = trim((string)($body['usuario'] ?? ($currentUser['usuario'] ?? 'Sistema')));
    $tipo = trim((string)($body['tipo'] ?? 'accion_general'));
    $descripcion = trim((string)($body['descripcion'] ?? 'Actividad registrada'));
    $detalles = isset($body['detalles']) && is_array($body['detalles']) ? $body['detalles'] : null;

    $logged = logActivity($usuario, $tipo, $descripcion, $detalles, $currentUser['id'] ?? null);

    sendJson([
        'ok'      => $logged,
        'message' => 'Actividad registrada con éxito.'
    ]);
}

sendJson(['error' => 'method_not_allowed'], 405);
