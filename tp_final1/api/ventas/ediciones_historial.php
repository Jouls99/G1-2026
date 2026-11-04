<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers.php';

handleOptions();
requireApiAuth();

$db = requireApiDatabase();
$user = getApiUser();
$userId = isset($user['id']) ? (int)$user['id'] : null;
$canSeeAll = isAdminApi() || canModifyReportsApi();

try {
    $where = [
        "a.`tipo_accion` IN ('venta_modificada', 'venta_eliminada', 'venta_deshabilitada')"
    ];
    $params = [];

    if (!$canSeeAll) {
        $where[] = '(a.`id_usuario` = :user_id OR (a.`id_usuario` IS NULL AND LOWER(a.`usuario`) = LOWER(:username)))';
        $params[':user_id'] = $userId;
        $params[':username'] = (string)($user['usuario'] ?? '');
    }

    $stmt = $db->prepare("
        SELECT
            a.`id_actividad`,
            a.`id_usuario`,
            a.`usuario`,
            a.`tipo_accion`,
            a.`descripcion`,
            a.`detalles`,
            a.`fecha`,
            COALESCE(u.`rol`, 'vendedor') AS rol
        FROM `actividad_usuario` a
        LEFT JOIN `usuario` u ON u.`id_usuario` = a.`id_usuario`
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.`fecha` DESC, a.`id_actividad` DESC
        LIMIT 200
    ");
    $stmt->execute($params);

    $historial = [];
    foreach ($stmt->fetchAll() as $row) {
        $detalles = null;
        if (!empty($row['detalles'])) {
            $decoded = json_decode((string)$row['detalles'], true);
            $detalles = is_array($decoded) ? $decoded : (string)$row['detalles'];
        }

        $fecha = new DateTimeImmutable((string)$row['fecha']);
        $detalleTexto = (string)($row['descripcion'] ?? '');
        if (is_array($detalles) && $detalles !== []) {
            $detalleTexto .= ' | ' . json_encode($detalles, JSON_UNESCAPED_UNICODE);
        } elseif (is_string($detalles) && $detalles !== '') {
            $detalleTexto .= ' | ' . $detalles;
        }

        $historial[] = [
            'id' => (int)$row['id_actividad'],
            'usuario_id' => $row['id_usuario'] !== null ? (int)$row['id_usuario'] : null,
            'usuario' => (string)$row['usuario'],
            'rol' => (string)$row['rol'],
            'accion' => (string)$row['tipo_accion'],
            'fecha' => $fecha->format('d/m/Y'),
            'hora' => $fecha->format('H:i:s'),
            'detalles_cambios' => $detalleTexto
        ];
    }

    sendJson($historial);
} catch (Throwable $e) {
    error_log('Error al consultar el historial de ediciones de ventas: ' . $e->getMessage());
    sendJson([
        'ok' => false,
        'error' => 'history_load_failed',
        'message' => 'No se pudo cargar el historial de ediciones.'
    ], 500);
}
