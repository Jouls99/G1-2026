<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

handleOptions();

$method = requestMethod();
$currentUser = getApiUser();

if ($method === 'GET') {
    // El feed y sus métricas solo se exponen a administradores; el Super Admin ve todos los roles.
    // Administradores pueden consultar el registro general de actividades.
    requireAdminApi();

    $db = requireApiDatabase();
    $filtroUsuario = trim((string)($_GET['usuario'] ?? ''));
    $filtroTipo = trim((string)($_GET['tipo'] ?? ''));
    $limit = min(500, max(1, (int)($_GET['limit'] ?? 200)));
    // Carga señales de seguridad y normaliza filtros comunes antes de consultar el historial.
    $amenazaStmt = $db->query("
        SELECT `id_amenaza`, `regla`, `clave`, `titulo`, `descripcion`, `usuario`, `ip`,
               `intentos`, `fecha`, `ventana_minutos`
        FROM `amenaza`
        ORDER BY `fecha` DESC
        LIMIT 1000
    ");
    $amenazas = array_map(static function (array $row): array {
        return [
            'id' => (string)$row['id_amenaza'],
            'regla' => (string)$row['regla'],
            'clave' => (string)$row['clave'],
            'titulo' => (string)$row['titulo'],
            'descripcion' => (string)$row['descripcion'],
            'usuario' => $row['usuario'],
            'ip' => $row['ip'],
            'intentos' => (int)$row['intentos'],
            'fecha' => date('c', strtotime((string)$row['fecha'])),
            'ventana_minutos' => (int)$row['ventana_minutos']
        ];
    }, $amenazaStmt->fetchAll());

    $actividades = [];
    $stats = [
        'total'          => 0,
        'ventas'         => 0,
        'logins'         => 0,
        'logins_hoy'     => 0,
        'intentos_fallidos' => 0,
        'stock'          => 0,
        'roles'          => 0
    ];

    // Construye el filtro con parámetros enlazados, excluyendo cuentas Super Admin si corresponde.
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

        if (!isSuperAdminApi()) {
            $where[] = "NOT EXISTS (
                SELECT 1
                FROM `usuario` superadmin
                WHERE LOWER(TRIM(superadmin.`rol`)) IN ('superadmin', 'super admin', 'super administrador', 'super_admin', 'super-admin', 'super_administrador', 'super-administrador', 'superadministrator', 'super administrator')
                  AND (
                      superadmin.`id_usuario` = a.`id_usuario`
                      OR LOWER(superadmin.`nombre`) = LOWER(a.`usuario`)
                  )
            )";
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
            LEFT JOIN `usuario` u ON (a.`id_usuario` IS NOT NULL AND a.`id_usuario` = u.`id_usuario`)
                OR (a.`id_usuario` IS NULL AND LOWER(a.`usuario`) = LOWER(u.`nombre`))
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

        // Estadísticas generales de actividad y logins
        $statsStmt = $db->query("
            SELECT 
                COUNT(DISTINCT a.`id_actividad`) AS total,
                SUM(CASE WHEN a.`tipo_accion` LIKE 'venta%' THEN 1 ELSE 0 END) AS ventas,
                SUM(CASE WHEN a.`tipo_accion` = 'login_exitoso' OR a.`tipo_accion` LIKE 'registro%' THEN 1 ELSE 0 END) AS logins,
                SUM(CASE WHEN (a.`tipo_accion` = 'login_exitoso' OR a.`tipo_accion` LIKE 'registro%') AND DATE(a.`fecha`) = CURDATE() THEN 1 ELSE 0 END) AS logins_hoy,
                SUM(CASE WHEN a.`tipo_accion` = 'login_fallido' THEN 1 ELSE 0 END) AS intentos_fallidos,
                SUM(CASE WHEN a.`tipo_accion` LIKE 'stock%' THEN 1 ELSE 0 END) AS stock,
                SUM(CASE WHEN a.`tipo_accion` LIKE '%rol%' OR a.`tipo_accion` LIKE '%usuario%' THEN 1 ELSE 0 END) AS roles
            FROM `actividad_usuario` a
            " . (isSuperAdminApi() ? "" : "WHERE NOT EXISTS (
                SELECT 1
                FROM `usuario` superadmin
                WHERE LOWER(TRIM(superadmin.`rol`)) IN ('superadmin', 'super admin', 'super administrador', 'super_admin', 'super-admin', 'super_administrador', 'super-administrador', 'superadministrator', 'super administrator')
                  AND (
                      superadmin.`id_usuario` = a.`id_usuario`
                      OR LOWER(superadmin.`nombre`) = LOWER(a.`usuario`)
                  )
            )") . "
        ");
        $statRow = $statsStmt->fetch();
        if ($statRow) {
            $stats = [
                'total'             => (int)$statRow['total'],
                'ventas'            => (int)($statRow['ventas'] ?? 0),
                'logins'            => (int)($statRow['logins'] ?? 0),
                'logins_hoy'        => (int)($statRow['logins_hoy'] ?? 0),
                'intentos_fallidos' => (int)($statRow['intentos_fallidos'] ?? 0),
                'stock'             => (int)($statRow['stock'] ?? 0),
                'roles'             => (int)($statRow['roles'] ?? 0),
            ];
        }

        sendJson([
            'ok'          => true,
            'actividades' => $actividades,
            'amenazas'    => $amenazas,
            'stats'       => $stats,
            'fuente'      => 'mysql'
        ]);
    } catch (Throwable $e) {
        error_log('Error al consultar las actividades: ' . $e->getMessage());
        sendJson(['ok' => false, 'message' => 'No se pudieron cargar las actividades.'], 500);
    }
}

if ($method === 'POST') {
    // Permite registrar una acción de la sesión actual con datos estructurados opcionales.
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
