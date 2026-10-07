<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

handleOptions();

$method = requestMethod();
$db = requireApiDatabase();

requireApiAuth();

// GET: Listar ventas activas o consultar el historial semanal/de una fecha exacta.
if ($method === 'GET') {
    $ventas = [];
    try {
        $historyDate = isset($_GET['fecha']) ? trim((string)$_GET['fecha']) : null;
        $historyWeek = isset($_GET['semana']) ? trim((string)$_GET['semana']) : null;
        $historyMode = $historyDate !== null || $historyWeek !== null;
        $queryParams = [];
        $sourceQuery = '';

        if ($historyMode) {
            if (!isAdminApi()) {
                sendJson(['ok' => false, 'error' => 'forbidden', 'message' => 'Solo la administradora puede consultar el historial semanal.'], 403);
            }
            if ($historyDate !== null && $historyWeek !== null) {
                sendJson(['ok' => false, 'error' => 'invalid_filter', 'message' => 'Elegí una fecha exacta o una semana, no ambas.'], 400);
            }

            $timezone = new DateTimeZone('America/Argentina/Buenos_Aires');
            $today = new DateTimeImmutable('today', $timezone);
            $currentWeek = $today->modify('monday this week');
            $oldestWeek = $currentWeek->modify('-3 weeks');
            $selectedDate = null;

            if ($historyDate !== null) {
                $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $historyDate, $timezone);
                if (!$parsedDate || $parsedDate->format('Y-m-d') !== $historyDate) {
                    sendJson(['ok' => false, 'error' => 'invalid_date', 'message' => 'La fecha debe tener el formato AAAA-MM-DD.'], 400);
                }
                $selectedDate = $parsedDate;
                $selectedWeek = $parsedDate->modify('monday this week');
            } else {
                $parsedWeek = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$historyWeek, $timezone);
                if (!$parsedWeek || $parsedWeek->format('Y-m-d') !== $historyWeek || $parsedWeek->format('N') !== '1') {
                    sendJson(['ok' => false, 'error' => 'invalid_week', 'message' => 'La semana debe indicarse con la fecha de su lunes (AAAA-MM-DD).'], 400);
                }
                $selectedWeek = $parsedWeek;
            }

            if ($selectedWeek < $oldestWeek || $selectedWeek > $currentWeek
                || ($selectedDate !== null && $selectedDate > $today)) {
                sendJson(['ok' => false, 'error' => 'outside_retention', 'message' => 'La fecha o semana solicitada está fuera de las últimas cuatro semanas disponibles.'], 400);
            }

            $rangeStart = $selectedDate ?? $selectedWeek;
            $rangeEnd = $selectedDate
                ? $selectedDate->modify('+1 day')
                : $selectedWeek->modify('+1 week');
            $startSql = $rangeStart->format('Y-m-d 00:00:00');
            $endSql = $rangeEnd->format('Y-m-d 00:00:00');
            $historyFilter = $selectedDate !== null
                ? '`fecha` >= :history_start AND `fecha` < :history_end'
                : '`semana_inicio` = :history_week';
            $sourceQuery = "
                SELECT `ID_factura`, `fecha`, `cantidadVendida`, `precioFinal`, `ganancia`, `ID_stock`, `nombre_producto`, `usuario`, 0 AS archivada
                FROM `ventas`
                WHERE `fecha` >= :live_start AND `fecha` < :live_end
                UNION ALL
                SELECT `ID_factura`, `fecha`, `cantidadVendida`, `precioFinal`, `ganancia`, `ID_stock`, `nombre_producto`, `usuario`, 1 AS archivada
                FROM `ventas_historial`
                WHERE {$historyFilter}
            ";
            $queryParams = [
                ':live_start' => $startSql,
                ':live_end' => $endSql
            ];
            if ($selectedDate !== null) {
                $queryParams[':history_start'] = $startSql;
                $queryParams[':history_end'] = $endSql;
            } else {
                $queryParams[':history_week'] = $selectedWeek->format('Y-m-d');
            }
        } else {
            $timezone = new DateTimeZone('America/Argentina/Buenos_Aires');
            $today = new DateTimeImmutable('today', $timezone);
            $retentionStart = $today->modify('monday this week')->modify('-3 weeks')->format('Y-m-d 00:00:00');
            $tomorrow = $today->modify('+1 day')->format('Y-m-d 00:00:00');
            $sourceQuery = "
                SELECT `ID_factura`, `fecha`, `cantidadVendida`, `precioFinal`, `ganancia`, `ID_stock`, `nombre_producto`, `usuario`, 0 AS archivada
                FROM `ventas`
                WHERE `fecha` >= :live_start AND `fecha` < :live_end
                UNION ALL
                SELECT `ID_factura`, `fecha`, `cantidadVendida`, `precioFinal`, `ganancia`, `ID_stock`, `nombre_producto`, `usuario`, 1 AS archivada
                FROM `ventas_historial`
                WHERE `fecha` >= :history_start AND `fecha` < :history_end
            ";
            $queryParams = [
                ':live_start' => $retentionStart,
                ':live_end' => $tomorrow,
                ':history_start' => $retentionStart,
                ':history_end' => $tomorrow
            ];
        }

        $stmt = $db->prepare("
                SELECT 
                    f.ID_factura,
                    f.fecha,
                    f.cantidadVendida,
                    CAST(f.precioFinal AS DECIMAL(10,2)) AS precioFinal,
                    CAST(f.ganancia AS DECIMAL(10,2)) AS ganancia,
                    f.ID_stock,
                    COALESCE(f.usuario, 'gomez11') AS usuario,
                    COALESCE(NULLIF(f.nombre_producto, ''), p.nombre, 'Nombre no disponible') AS nombre_producto,
                    COALESCE(p.codigo, CONCAT('COD-', f.ID_stock)) AS codigo_producto,
                    p.marca,
                    p.sub_nombre,
                    COALESCE(sc.nombre, p.subcategoria) AS subcategoria,
                    CASE
                        WHEN c.nombre IS NULL OR LOWER(TRIM(c.nombre)) IN ('general', 'sin categoría', 'sin categoria') THEN NULL
                        ELSE c.nombre
                    END AS categoria,
                    f.archivada
                FROM ({$sourceQuery}) f
                LEFT JOIN `producto` p ON f.`ID_stock` = p.`ID_stock`
                LEFT JOIN `categoria` c ON p.`ID_categoria` = c.`ID_categoria`
                LEFT JOIN `sub_categoria` sc ON p.`ID_sub_categoria` = sc.`ID_sub_categoria`
                ORDER BY f.fecha DESC, f.ID_factura DESC
            ");
        $stmt->execute($queryParams);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $ventas[] = [
                'id'        => (string)$row['ID_factura'],
                'ID_factura'=> (int)$row['ID_factura'],
                'usuario'   => (string)$row['usuario'],
                'fecha'     => $row['fecha']
                    ? (new DateTimeImmutable((string)$row['fecha'], new DateTimeZone('America/Argentina/Buenos_Aires')))->format('c')
                    : (new DateTimeImmutable('now', new DateTimeZone('America/Argentina/Buenos_Aires')))->format('c'),
                'total'     => (float)$row['ganancia'],
                'dinero'    => (float)$row['ganancia'],
                'archivada' => (bool)$row['archivada'],
                'semana_inicio' => (new DateTimeImmutable((string)$row['fecha'], new DateTimeZone('America/Argentina/Buenos_Aires')))->modify('monday this week')->format('Y-m-d'),
                'productos' => [
                    [
                        'id'        => (int)$row['ID_stock'],
                        'ID_stock'  => (int)$row['ID_stock'],
                        'nombre'    => (string)$row['nombre_producto'],
                        'codigo'    => (string)$row['codigo_producto'],
                        'sub_nombre' => $row['sub_nombre'] !== null ? (string)$row['sub_nombre'] : null,
                        'subcategoria' => $row['subcategoria'] !== null ? (string)$row['subcategoria'] : null,
                        'categoria' => (string)$row['categoria'],
                        'marca' => $row['marca'] !== null ? (string)$row['marca'] : null,
                        'cantidad'  => (int)$row['cantidadVendida'],
                        'precio'    => (float)$row['precioFinal']
                    ]
                ]
            ];
        }
    } catch (Exception $e) {
        error_log('Error al consultar ventas: ' . $e->getMessage());
        sendJson(['ok' => false, 'message' => 'No se pudieron cargar las ventas.'], 500);
    }

    sendJson($ventas);
}

// POST: Registrar una nueva venta y actualizar el stock en una transacción MySQL.
if ($method === 'POST') {
    $payload = getJsonBody();

    if (
        !is_array($payload)
        || !isset($payload['productos'])
        || !is_array($payload['productos'])
        || count($payload['productos']) === 0
    ) {
        sendJson(['error' => 'invalid_payload', 'message' => 'La venta debe contener al menos un producto.'], 400);
    }

    foreach ($payload['productos'] as $prod) {
        if (!is_array($prod)) {
            continue;
        }

        $cantidad = (int)($prod['cantidad'] ?? 1);
        $precio = (float)($prod['precio'] ?? 0);

        if ($cantidad < 0 || $precio < 0) {
            sendJson([
                'error' => 'invalid_payload',
                'message' => 'Cantidad y precio no pueden ser negativos. La ganancia puede salir negativa solo como pérdida.',
            ], 400);
        }
    }

    $usuarioVendedor = $_SESSION['user']['usuario'] ?? 'gomez11';
    $userIdVendedor = $_SESSION['user']['id'] ?? null;

    $businessTimezone = new DateTimeZone('America/Argentina/Buenos_Aires');
    $saleDate = new DateTimeImmutable('now', $businessTimezone);
    foreach (['fecha', 'fecha_hora'] as $dateField) {
        if (!empty($payload[$dateField])) {
            try {
                $saleDate = new DateTimeImmutable((string)$payload[$dateField], $businessTimezone);
            } catch (Throwable $e) {
                sendJson(['ok' => false, 'error' => 'invalid_date', 'message' => 'La fecha de venta no es válida.'], 400);
            }
        }
    }
    $fechaSql = $saleDate->format('Y-m-d H:i:s');

    $totalCalculado = 0;
    $productosVenta = [];
    $facturasIds = [];

    try {
        $db->beginTransaction();

            $stmtFindProd = $db->prepare("
                SELECT p.`ID_stock`, p.`cantTotal`, p.`cantVendida`, p.`precio`, p.`nombre`,
                       p.`ID_categoria`, c.`nombre` AS `categoria`
                FROM `producto` p
                INNER JOIN `categoria` c ON c.`ID_categoria` = p.`ID_categoria`
                WHERE (LOWER(p.`codigo`) = LOWER(:codigo) OR p.`ID_stock` = :id)
                  AND LOWER(TRIM(c.`nombre`)) NOT IN ('general', 'sin categoría', 'sin categoria')
                LIMIT 1
            ");

            $stmtInsertFactura = $db->prepare("
                INSERT INTO `ventas` (`fecha`, `cantidadVendida`, `precioFinal`, `ID_stock`, `nombre_producto`, `usuario`)
                VALUES (:fecha, :cantidad, :precio, :id_stock, :nombre_producto, :usuario)
            ");

            $stmtUpdateProd = $db->prepare("
                UPDATE `producto`
                SET `cantTotal` = GREATEST(0, `cantTotal` - :cant_sub),
                    `cantVendida` = COALESCE(`cantVendida`, 0) + :cant_add
                WHERE `ID_stock` = :id_stock
            ");

            foreach ($payload['productos'] as $prod) {
                if (!is_array($prod) || empty($prod['codigo'])) {
                    continue;
                }

                $codigo = trim((string)$prod['codigo']);
                $idProd = (int)($prod['ID_stock'] ?? ($prod['id'] ?? 0));
                $cantidad = (int)($prod['cantidad'] ?? 1);
                $precio = (float)($prod['precio'] ?? 0);
                // Buscar en DB
                $stmtFindProd->execute([
                    ':codigo' => $codigo,
                    ':id'     => $idProd
                ]);
                $dbProd = $stmtFindProd->fetch();

                if (!$dbProd || empty($dbProd['ID_categoria'])) {
                    $db->rollBack();
                    sendJson(['error' => 'product_category_required', 'message' => 'El producto no existe o no tiene una categoría asignada.'], 409);
                }
                $idStock = (int)$dbProd['ID_stock'];
                $categoria = (string)$dbProd['categoria'];

                // Insertar factura
                $stmtInsertFactura->execute([
                    ':fecha'    => $fechaSql,
                    ':cantidad' => $cantidad,
                    ':precio'   => $precio,
                    ':id_stock' => $idStock,
                    ':nombre_producto' => (string)$dbProd['nombre'],
                    ':usuario'  => $usuarioVendedor
                ]);
                $facturaId = (int)$db->lastInsertId();
                $facturasIds[] = $facturaId;

                // Descontar stock y sumar vendido
                $stmtUpdateProd->execute([
                    ':cant_sub' => $cantidad,
                    ':cant_add' => $cantidad,
                    ':id_stock' => $idStock
                ]);

                $subtotal = $precio * $cantidad;
                $totalCalculado += $subtotal;

                $productosVenta[] = [
                    'id'        => $idStock,
                    'ID_stock'  => $idStock,
                    'nombre'    => (string)$dbProd['nombre'],
                    'codigo'    => $codigo,
                    'cantidad'  => $cantidad,
                    'precio'    => $precio,
                    'categoria' => $categoria
                ];
            }

        if ($productosVenta === []) {
            $db->rollBack();
            sendJson(['error' => 'invalid_payload', 'message' => 'La venta no contiene productos válidos con código.'], 400);
        }
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Error al registrar venta: ' . $e->getMessage());
        sendJson(['ok' => false, 'error' => 'save_error', 'message' => 'No se pudo registrar la venta en la base de datos.'], 500);
    }

    // Preparar respuesta desde los registros persistidos en ventas.
    $totalVenta = (float)($payload['total'] ?? $totalCalculado);
    $ventaResult = [
        'id'          => (string)($facturasIds[0] ?? (string)time()),
        'factura_ids' => $facturasIds,
        'usuario'     => $usuarioVendedor,
        'productos'   => $productosVenta,
        'total'       => $totalVenta,
        'dinero'      => (float)($payload['dinero'] ?? $totalVenta),
        'fecha'       => $saleDate->format('c')
    ];

    // Registrar actividad
    $cantArticulos = array_sum(array_column($productosVenta, 'cantidad'));
    logActivity(
        $usuarioVendedor,
        'venta_registrada',
        "Venta registrada por \${$totalVenta} ({$cantArticulos} unidades)",
        [
            'factura_ids' => $facturasIds,
            'total'       => $totalVenta,
            'articulos'   => $cantArticulos,
            'productos'   => array_map(fn($p) => $p['nombre'] . ' (x' . $p['cantidad'] . ')', $productosVenta)
        ],
        $userIdVendedor ? (int)$userIdVendedor : null
    );

    sendJson([
        'ok'         => true,
        'message'    => 'Venta registrada con éxito. Stock y ganancias actualizados.',
        'venta'      => $ventaResult,
        'totalVenta' => $totalVenta
    ]);
}

// PUT: Actualizar o modificar una venta existente
if ($method === 'PUT') {
    $payload = getJsonBody();

    if (!canModifyReportsApi()) {
        sendJson(['ok' => false, 'error' => 'forbidden', 'message' => 'No tenés permiso para modificar ventas desde informes.'], 403);
    }

    if (!is_array($payload)) {
        sendJson(['error' => 'invalid_payload', 'message' => 'El cuerpo de la solicitud es inválido.'], 400);
    }

    if (isset($payload[0]) || $payload === []) {
        sendJson([
            'ok' => false,
            'error' => 'bulk_update_not_supported',
            'message' => 'No se permite reemplazar todo el historial. Modificá o eliminá las ventas por su ID.'
        ], 400);
    }

    // Si es una sola venta
    $invoiceId = (int)($payload['id'] ?? ($_GET['id'] ?? 0));
    if ($invoiceId < 1) {
        sendJson(['ok' => false, 'error' => 'invalid_payload', 'message' => 'Falta el ID de la venta.'], 400);
    }

    $newQuantity = null;
    $code = null;
    $price = null;
    $productName = 'Producto';

    if (!empty($payload['productos']) && is_array($payload['productos'])) {
        $firstProd = $payload['productos'][0];
        $newQuantity = isset($firstProd['cantidad']) ? (int)$firstProd['cantidad'] : null;
        $code = trim((string)($firstProd['codigo'] ?? ''));
        $price = isset($firstProd['precio']) ? (float)$firstProd['precio'] : null;
        $productName = trim((string)($firstProd['nombre'] ?? 'Producto'));
    }

    if ($newQuantity === null && isset($payload['cantidad'])) {
        $newQuantity = (int)$payload['cantidad'];
    }
    if ($code === null && isset($payload['codigo'])) {
        $code = trim((string)$payload['codigo']);
    }
    if ($price === null && isset($payload['precio'])) {
        $price = (float)$payload['precio'];
    }

    if ($newQuantity === null) {
        sendJson(['ok' => false, 'error' => 'invalid_payload', 'message' => 'Indicá la nueva cantidad del producto.'], 400);
    }

    // Caso A: Cantidad 0 o negativa -> Eliminar la venta y devolver todo el stock
    if ($newQuantity <= 0) {
        $deletedQty = 0;
        $deletedProdId = 0;
        $deletedProdCode = $code ?? '';
        $deletedProdName = $productName;

        try {
            $db->beginTransaction();
            $find = $db->prepare("
                    SELECT f.`ID_factura`, f.`cantidadVendida`, f.`ID_stock`, f.`precioFinal`, p.`nombre`, p.`codigo`
                    FROM `ventas` f
                    LEFT JOIN `producto` p ON p.`ID_stock` = f.`ID_stock`
                    WHERE f.`ID_factura` = :id
                    FOR UPDATE
                ");
            $find->execute([':id' => $invoiceId]);
            $row = $find->fetch();
            if (!$row) {
                $db->rollBack();
                sendJson(['ok' => false, 'error' => 'not_found', 'message' => 'No se encontró la venta.'], 404);
            }

            $deletedQty = (int)$row['cantidadVendida'];
            $deletedProdId = (int)($row['ID_stock'] ?? 0);
            $deletedProdCode = (string)($row['codigo'] ?? $deletedProdCode);
            $deletedProdName = (string)($row['nombre'] ?? $deletedProdName);

            if ($deletedProdId > 0) {
                $upd = $db->prepare("
                            UPDATE `producto`
                            SET `cantTotal` = `cantTotal` + :cant_add,
                                `cantVendida` = GREATEST(0, COALESCE(`cantVendida`, 0) - :cant_sub)
                            WHERE `ID_stock` = :id_stock
                        ");
                $upd->execute([
                    ':cant_add' => $deletedQty,
                    ':cant_sub' => $deletedQty,
                    ':id_stock' => $deletedProdId
                ]);
            }

            $del = $db->prepare("DELETE FROM `ventas` WHERE `ID_factura` = :id");
            $del->execute([':id' => $invoiceId]);
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Error al eliminar venta en base de datos: ' . $e->getMessage());
            sendJson(['ok' => false, 'message' => 'No se pudo eliminar la venta.'], 500);
        }

        $currentUser = getApiUser();
        $uName = $currentUser['usuario'] ?? 'Administrador';
        logActivity(
            $uName,
            'venta_eliminada',
            "Venta #{$invoiceId} ({$deletedProdName}) eliminada por {$uName}. Stock devuelto.",
            ['id_factura' => $invoiceId, 'producto' => $deletedProdName, 'cantidad' => $deletedQty],
            $currentUser['id'] ?? null
        );

        sendJson(['ok' => true, 'deleted' => true, 'message' => 'La venta quedó en 0 productos y fue eliminada del historial. Stock devuelto al inventario.']);
    }

    // Caso B: Cantidad mayor a 0 -> Modificar venta y ajustar diferencia de stock
    $oldQuantity = null;
    $productId = 0;
    $difference = 0;

    try {
        $db->beginTransaction();
        $find = $db->prepare("
                SELECT f.`ID_factura`, f.`cantidadVendida`, f.`ID_stock`, f.`precioFinal`, p.`nombre`, p.`cantTotal`, p.`cantVendida`, p.`codigo`
                FROM `ventas` f
                JOIN `producto` p ON p.`ID_stock` = f.`ID_stock`
                WHERE f.`ID_factura` = :id
                FOR UPDATE
            ");
        $find->execute([':id' => $invoiceId]);
        $invoice = $find->fetch();

        if (!$invoice) {
            $findSolo = $db->prepare("SELECT * FROM `ventas` WHERE `ID_factura` = :id FOR UPDATE");
            $findSolo->execute([':id' => $invoiceId]);
            $invoice = $findSolo->fetch();
        }

        if (!$invoice) {
            $db->rollBack();
            sendJson(['ok' => false, 'error' => 'not_found', 'message' => 'No se encontró la venta.'], 404);
        }
                $oldQuantity = (int)$invoice['cantidadVendida'];
                $productId = (int)($invoice['ID_stock'] ?? 0);
                $currentStock = (int)($invoice['cantTotal'] ?? 0);
                $price = $price !== null ? $price : (float)$invoice['precioFinal'];
                $code = $code ?: (string)($invoice['codigo'] ?? '');
                $productName = (string)($invoice['nombre'] ?? $productName);

                $difference = $newQuantity - $oldQuantity;
                if ($difference > 0 && $currentStock < $difference) {
                    $db->rollBack();
                    sendJson(['ok' => false, 'message' => "Stock insuficiente para '{$productName}'. Disponible: {$currentStock}, Requerido adicional: {$difference}"], 409);
                }

                if ($productId > 0) {
                    $stockUpdate = $db->prepare("
                        UPDATE `producto`
                        SET `cantTotal` = `cantTotal` - :diff_sub,
                            `cantVendida` = GREATEST(0, COALESCE(`cantVendida`, 0) + :diff_add)
                        WHERE `ID_stock` = :id_stock
                    ");
                    $stockUpdate->execute([
                        ':diff_sub' => $difference,
                        ':diff_add' => $difference,
                        ':id_stock' => $productId
                    ]);
                }

                $invoiceUpdate = $db->prepare("
                    UPDATE `ventas`
                    SET `cantidadVendida` = :quantity,
                        `precioFinal` = :precio
                    WHERE `ID_factura` = :id
                ");
                $invoiceUpdate->execute([
                    ':quantity' => $newQuantity,
                    ':precio'   => $price,
                    ':id'       => $invoiceId
                ]);

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Error al modificar venta en base de datos: ' . $e->getMessage());
        sendJson(['ok' => false, 'message' => 'No se pudo actualizar la venta en la base de datos.'], 500);
    }

    $currentUser = getApiUser();
    $userName = (string)($currentUser['usuario'] ?? 'Administrador');
    logActivity(
        $userName,
        'venta_modificada',
        "Venta #{$invoiceId} ({$productName}) modificada por {$userName}. Nueva cantidad: {$newQuantity}",
        ['id' => $invoiceId, 'codigo' => $code, 'cantidad' => $newQuantity],
        isset($currentUser['id']) ? (int)$currentUser['id'] : null
    );

    sendJson(['ok' => true, 'message' => 'Venta modificada con éxito y stock actualizado.']);
}

// DELETE: Eliminar una venta / factura por ID y restaurar stock
if ($method === 'DELETE') {
    if (!canModifyReportsApi()) {
        sendJson(['ok' => false, 'error' => 'forbidden', 'message' => 'No tenés permiso para eliminar ventas.'], 403);
    }

    $id = $_GET['id'] ?? null;
    if (!$id) {
        $body = getJsonBody();
        $id = $body['id'] ?? null;
    }

    if (!$id) {
        sendJson(['ok' => false, 'error' => 'missing_id', 'message' => 'Se requiere el parámetro ID de la venta.'], 400);
    }

    $idInt = (int)$id;
    $deletedQty = 0;
    $deletedProdId = 0;
    $deletedProdCode = '';
    $deletedProdName = 'Producto';

    try {
        $db->beginTransaction();

        $find = $db->prepare("
                SELECT f.`ID_factura`, f.`cantidadVendida`, f.`ID_stock`, f.`precioFinal`, p.`nombre`, p.`codigo`
                FROM `ventas` f
                LEFT JOIN `producto` p ON p.`ID_stock` = f.`ID_stock`
                WHERE f.`ID_factura` = :id
                FOR UPDATE
            ");
        $find->execute([':id' => $idInt]);
        $row = $find->fetch();
        if (!$row) {
            $db->rollBack();
            sendJson(['ok' => false, 'error' => 'not_found', 'message' => 'No se encontró la venta.'], 404);
        }

        $deletedQty = (int)$row['cantidadVendida'];
        $deletedProdId = (int)($row['ID_stock'] ?? 0);
        $deletedProdCode = (string)($row['codigo'] ?? '');
        $deletedProdName = (string)($row['nombre'] ?? 'Producto');

        // Restaurar stock en MySQL producto
        if ($deletedProdId > 0) {
            $stmtUpd = $db->prepare("
                        UPDATE `producto`
                        SET `cantTotal` = `cantTotal` + :cant_add,
                            `cantVendida` = GREATEST(0, COALESCE(`cantVendida`, 0) - :cant_sub)
                        WHERE `ID_stock` = :id_stock
                    ");
            $stmtUpd->execute([
                ':cant_add' => $deletedQty,
                ':cant_sub' => $deletedQty,
                ':id_stock' => $deletedProdId
            ]);
        }

        // Eliminar el registro activo de ventas.
        $stmtDel = $db->prepare("DELETE FROM `ventas` WHERE `ID_factura` = :id");
        $stmtDel->execute([':id' => $idInt]);

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Error al eliminar venta en base de datos: ' . $e->getMessage());
        sendJson(['ok' => false, 'message' => 'No se pudo eliminar la venta.'], 500);
    }

    // Registrar actividad
    $currentUser = getApiUser();
    $uName = $currentUser['usuario'] ?? 'Administrador';
    logActivity(
        $uName,
        'venta_eliminada',
        "Venta #{$id} ({$deletedProdName}) eliminada por {$uName}. {$deletedQty} unidades devueltas al inventario.",
        ['id_factura' => $id, 'producto' => $deletedProdName, 'unidades_devueltas' => $deletedQty],
        $currentUser['id'] ?? null
    );

    sendJson(['ok' => true, 'message' => 'Venta eliminada correctamente y stock devuelto al inventario.']);
}
sendJson(['error' => 'method_not_allowed'], 405);