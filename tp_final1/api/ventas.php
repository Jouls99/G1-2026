<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

handleOptions();

$ventasFile = dataPath('ventas.json');
$inventarioFile = dataPath('inventario.json');
$method = requestMethod();
$db = getDBConnection();

requireApiAuth();

// GET: Listar ventas desde la base de datos MySQL (tabla `facturacion` y `producto`) o fallback a ventas.json
if ($method === 'GET') {
    $ventas = [];
    if ($db !== null) {
        try {
            $stmt = $db->query("
                SELECT 
                    f.ID_factura,
                    f.fecha,
                    f.cantidadVendida,
                    CAST(f.precioFinal AS DECIMAL(10,2)) AS precioFinal,
                    CAST(f.ganancia AS DECIMAL(10,2)) AS ganancia,
                    f.ID_stock,
                    COALESCE(f.usuario, 'gomez11') AS usuario,
                    COALESCE(p.nombre, 'Producto') AS nombre_producto,
                    COALESCE(p.codigo, CONCAT('COD-', f.ID_stock)) AS codigo_producto,
                    COALESCE(c.nombre, 'General') AS categoria
                FROM `facturacion` f
                LEFT JOIN `producto` p ON f.ID_stock = p.ID_stock
                LEFT JOIN `categoria` c ON p.ID_categoria = c.ID_categoria
                ORDER BY f.ID_factura DESC
            ");
            $rows = $stmt->fetchAll();

            foreach ($rows as $row) {
                $ventas[] = [
                    'id'        => (string)$row['ID_factura'],
                    'ID_factura'=> (int)$row['ID_factura'],
                    'usuario'   => (string)$row['usuario'],
                    'fecha'     => $row['fecha'] ? date('c', strtotime((string)$row['fecha'])) : date('c'),
                    'total'     => (float)$row['ganancia'],
                    'dinero'    => (float)$row['ganancia'],
                    'productos' => [
                        [
                            'id'        => (int)$row['ID_stock'],
                            'ID_stock'  => (int)$row['ID_stock'],
                            'nombre'    => (string)$row['nombre_producto'],
                            'codigo'    => (string)$row['codigo_producto'],
                            'cantidad'  => (int)$row['cantidadVendida'],
                            'precio'    => (float)$row['precioFinal'],
                            'categoria' => (string)$row['categoria']
                        ]
                    ]
                ];
            }
        } catch (Exception $e) {
            $ventas = [];
        }
    }

    // Si no hay ventas en DB pero sí en ventas.json, devolver las del JSON
    if (empty($ventas) && file_exists($ventasFile)) {
        $jsonVentas = readJsonFile($ventasFile);
        if (!empty($jsonVentas)) {
            sendJson($jsonVentas);
        }
    }

    sendJson($ventas);
}

// POST: Registrar una nueva venta (descuenta stock en MySQL y en inventario.json, registra factura y venta)
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

    $fechaSql = date('Y-m-d H:i:s');
    if (!empty($payload['fecha'])) {
        $ts = strtotime((string)$payload['fecha']);
        if ($ts !== false) {
            $fechaSql = date('Y-m-d H:i:s', $ts);
        }
    }
    if (!empty($payload['fecha_hora'])) {
        $ts = strtotime((string)$payload['fecha_hora']);
        if ($ts !== false) {
            $fechaSql = date('Y-m-d H:i:s', $ts);
        }
    }

    $totalCalculado = 0;
    $productosVenta = [];
    $facturasIds = [];

    // 1. Descontar stock y registrar facturación en MySQL
    if ($db !== null) {
        try {
            $db->beginTransaction();

            $stmtFindProd = $db->prepare("
                SELECT `ID_stock`, `cantTotal`, `cantVendida`, `precio`, `nombre`, `ID_categoria`
                FROM `producto`
                WHERE LOWER(`codigo`) = LOWER(:codigo) OR `ID_stock` = :id
                LIMIT 1
            ");

            $stmtInsertFactura = $db->prepare("
                INSERT INTO `facturacion` (`fecha`, `cantidadVendida`, `precioFinal`, `ID_stock`, `usuario`)
                VALUES (:fecha, :cantidad, :precio, :id_stock, :usuario)
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
                $nombre = trim((string)($prod['nombre'] ?? 'Producto'));
                $categoria = trim((string)($prod['categoria'] ?? 'General'));

                // Buscar en DB
                $stmtFindProd->execute([
                    ':codigo' => $codigo,
                    ':id'     => $idProd
                ]);
                $dbProd = $stmtFindProd->fetch();

                if (!$dbProd) {
                    $stmtNewProd = $db->prepare("
                        INSERT INTO `producto` (`nombre`, `codigo`, `cantTotal`, `cantVendida`, `precio`)
                        VALUES (:nombre, :codigo, 0, 0, :precio)
                    ");
                    $stmtNewProd->execute([
                        ':nombre' => $nombre,
                        ':codigo' => $codigo,
                        ':precio' => $precio
                    ]);
                    $idStock = (int)$db->lastInsertId();
                } else {
                    $idStock = (int)$dbProd['ID_stock'];
                }

                // Insertar factura
                $stmtInsertFactura->execute([
                    ':fecha'    => $fechaSql,
                    ':cantidad' => $cantidad,
                    ':precio'   => $precio,
                    ':id_stock' => $idStock,
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
                    'nombre'    => $nombre,
                    'codigo'    => $codigo,
                    'cantidad'  => $cantidad,
                    'precio'    => $precio,
                    'categoria' => $categoria
                ];
            }

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }
    }

    // 2. Si MySQL no estaba disponible o no se procesaron productos
    if (empty($productosVenta)) {
        foreach ($payload['productos'] as $prod) {
            $cantidad = (int)($prod['cantidad'] ?? 1);
            $precio = (float)($prod['precio'] ?? 0);
            $totalCalculado += $precio * $cantidad;
            $productosVenta[] = [
                'id'        => $prod['id'] ?? time(),
                'ID_stock'  => $prod['ID_stock'] ?? ($prod['id'] ?? time()),
                'nombre'    => (string)($prod['nombre'] ?? 'Producto'),
                'codigo'    => (string)($prod['codigo'] ?? ''),
                'cantidad'  => $cantidad,
                'precio'    => $precio,
                'categoria' => (string)($prod['categoria'] ?? 'General')
            ];
        }
    }

    // 3. Descontar stock e incrementar cantVendida en inventario.json
    $inventario = readJsonFile($inventarioFile);
    foreach ($productosVenta as $p) {
        $pCod = trim((string)($p['codigo'] ?? ''));
        $pId = (int)($p['ID_stock'] ?? ($p['id'] ?? 0));
        $pCant = (int)($p['cantidad'] ?? 1);

        $encontrado = false;
        foreach ($inventario as &$item) {
            $matchCode = $pCod !== '' && strcasecmp(trim((string)($item['codigo'] ?? '')), $pCod) === 0;
            $matchId = $pId > 0 && ((int)($item['ID_stock'] ?? ($item['id'] ?? 0)) === $pId);

            if ($matchCode || $matchId) {
                $stockActual = (int)($item['cantidad'] ?? ($item['stock'] ?? 0));
                $nuevoStock = max(0, $stockActual - $pCant);
                $item['cantidad'] = $nuevoStock;
                if (isset($item['stock'])) {
                    $item['stock'] = $nuevoStock;
                }
                $item['cantVendida'] = ((int)($item['cantVendida'] ?? 0)) + $pCant;
                $item['total'] = round($nuevoStock * ((float)($item['precio'] ?? 0)), 2);
                $encontrado = true;
                break;
            }
        }

        // Si el producto no estaba en inventario.json, agregarlo
        if (!$encontrado && $pCod !== '') {
            $inventario[] = [
                'id'          => $pId ?: time(),
                'ID_stock'    => $pId ?: time(),
                'nombre'      => $p['nombre'],
                'codigo'      => $pCod,
                'cantidad'    => 0,
                'cantVendida' => $pCant,
                'precio'      => (float)($p['precio'] ?? 0),
                'total'       => 0,
                'categoria'   => $p['categoria'] ?? 'General',
                'subcategoria'=> null
            ];
        }
    }
    writeJsonFile($inventarioFile, $inventario);

    // 4. Guardar venta en ventas.json
    $totalVenta = (float)($payload['total'] ?? $totalCalculado);
    $ventaResult = [
        'id'          => (string)($facturasIds[0] ?? (string)time()),
        'factura_ids' => $facturasIds,
        'usuario'     => $usuarioVendedor,
        'productos'   => $productosVenta,
        'total'       => $totalVenta,
        'dinero'      => (float)($payload['dinero'] ?? $totalVenta),
        'fecha'       => date('c')
    ];

    $ventas = readJsonFile($ventasFile);
    $ventas[] = $ventaResult;
    writeJsonFile($ventasFile, $ventas);

    // 5. Registrar actividad
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

    // Si es un array de ventas (backup o guardado masivo por Admin)
    if (isset($payload[0]) || empty($payload)) {
        if (!isAdminApi()) {
            sendJson(['ok' => false, 'error' => 'forbidden', 'message' => 'Solo los administradores pueden realizar actualizaciones masivas.'], 403);
        }
        writeJsonFile($ventasFile, $payload);

        $currentUser = getApiUser();
        $uName = $currentUser['usuario'] ?? 'Administrador';
        logActivity(
            $uName,
            'ventas_actualizadas',
            "Historial de ventas actualizado en el sistema por {$uName}",
            ['total_ventas' => count($payload)],
            $currentUser['id'] ?? null
        );

        sendJson(['ok' => true, 'message' => 'Ventas actualizadas correctamente.', 'count' => count($payload)]);
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

        if ($db !== null) {
            try {
                $db->beginTransaction();
                $find = $db->prepare("
                    SELECT f.`ID_factura`, f.`cantidadVendida`, f.`ID_stock`, f.`precioFinal`, p.`nombre`, p.`codigo`
                    FROM `facturacion` f
                    LEFT JOIN `producto` p ON p.`ID_stock` = f.`ID_stock`
                    WHERE f.`ID_factura` = :id
                    FOR UPDATE
                ");
                $find->execute([':id' => $invoiceId]);
                $row = $find->fetch();

                if ($row) {
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

                    $del = $db->prepare("DELETE FROM `facturacion` WHERE `ID_factura` = :id");
                    $del->execute([':id' => $invoiceId]);
                }
                $db->commit();
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('Error al eliminar venta 0 en DB: ' . $e->getMessage());
            }
        }

        // Sincronizar en inventario.json
        $inventory = readJsonFile($inventarioFile);
        if ($deletedQty > 0 || $deletedProdCode !== '') {
            foreach ($inventory as &$item) {
                $matchId = $deletedProdId > 0 && (int)($item['ID_stock'] ?? ($item['id'] ?? 0)) === $deletedProdId;
                $matchCode = $deletedProdCode !== '' && strcasecmp((string)($item['codigo'] ?? ''), $deletedProdCode) === 0;
                if ($matchId || $matchCode) {
                    $prevStock = (int)($item['cantidad'] ?? ($item['stock'] ?? 0));
                    $prevSold = (int)($item['cantVendida'] ?? 0);
                    $qtyToRestore = $deletedQty > 0 ? $deletedQty : (int)($item['cantVendida'] ?? 0);
                    $newStock = $prevStock + $qtyToRestore;
                    $item['cantidad'] = $newStock;
                    if (isset($item['stock'])) $item['stock'] = $newStock;
                    $item['cantVendida'] = max(0, $prevSold - $qtyToRestore);
                    $item['total'] = round($newStock * (float)($item['precio'] ?? 0), 2);
                    break;
                }
            }
            unset($item);
            writeJsonFile($inventarioFile, $inventory);
        }

        // Sincronizar en ventas.json
        $sales = readJsonFile($ventasFile);
        $sales = array_values(array_filter($sales, function ($v) use ($invoiceId) {
            return (int)($v['id'] ?? 0) !== $invoiceId && (int)($v['ID_factura'] ?? 0) !== $invoiceId;
        }));
        writeJsonFile($ventasFile, $sales);

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

    if ($db !== null) {
        try {
            $db->beginTransaction();
            $find = $db->prepare("
                SELECT f.`ID_factura`, f.`cantidadVendida`, f.`ID_stock`, f.`precioFinal`, p.`nombre`, p.`cantTotal`, p.`cantVendida`, p.`codigo`
                FROM `facturacion` f
                JOIN `producto` p ON p.`ID_stock` = f.`ID_stock`
                WHERE f.`ID_factura` = :id
                FOR UPDATE
            ");
            $find->execute([':id' => $invoiceId]);
            $invoice = $find->fetch();

            if (!$invoice) {
                // Si no tiene join de producto, buscar solo facturacion
                $findSolo = $db->prepare("SELECT * FROM `facturacion` WHERE `ID_factura` = :id FOR UPDATE");
                $findSolo->execute([':id' => $invoiceId]);
                $invoice = $findSolo->fetch();
            }

            if (!$invoice) {
                $db->rollBack();
                // Si no existe en DB, intentar fallback con JSON
            } else {
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
                    UPDATE `facturacion`
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
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Error al modificar venta en DB: ' . $e->getMessage());
            sendJson(['ok' => false, 'message' => 'No se pudo actualizar la venta en la base de datos.'], 500);
        }
    }

    // Sincronizar ventas.json
    $sales = readJsonFile($ventasFile);
    if ($oldQuantity === null) {
        foreach ($sales as $sale) {
            if ((int)($sale['id'] ?? 0) !== $invoiceId && (int)($sale['ID_factura'] ?? 0) !== $invoiceId) continue;
            foreach ($sale['productos'] ?? [] as $prod) {
                if ($code === '' || strcasecmp((string)($prod['codigo'] ?? ''), $code) === 0) {
                    $oldQuantity = (int)($prod['cantidad'] ?? 0);
                    $productId = (int)($prod['ID_stock'] ?? ($prod['id'] ?? 0));
                    $price = $price !== null ? $price : (float)($prod['precio'] ?? 0);
                    $code = $code ?: (string)($prod['codigo'] ?? '');
                    $productName = (string)($prod['nombre'] ?? $productName);
                    $difference = $newQuantity - $oldQuantity;
                    break 2;
                }
            }
        }
    }

    // Sincronizar inventario.json
    $inventory = readJsonFile($inventarioFile);
    foreach ($inventory as &$item) {
        $matchId = $productId > 0 && (int)($item['ID_stock'] ?? ($item['id'] ?? 0)) === $productId;
        $matchCode = $code !== '' && strcasecmp((string)($item['codigo'] ?? ''), (string)$code) === 0;
        if ($matchId || $matchCode) {
            $prevStock = (int)($item['cantidad'] ?? ($item['stock'] ?? 0));
            $prevSold = (int)($item['cantVendida'] ?? 0);
            $newStock = max(0, $prevStock - $difference);
            $item['cantidad'] = $newStock;
            if (isset($item['stock'])) $item['stock'] = $newStock;
            $item['cantVendida'] = max(0, $prevSold + $difference);
            $item['total'] = round($newStock * (float)($item['precio'] ?? $price), 2);
            break;
        }
    }
    unset($item);
    writeJsonFile($inventarioFile, $inventory);

    // Actualizar registro en ventas.json
    foreach ($sales as &$sale) {
        if ((int)($sale['id'] ?? 0) !== $invoiceId && (int)($sale['ID_factura'] ?? 0) !== $invoiceId) continue;
        if (isset($sale['productos']) && is_array($sale['productos'])) {
            foreach ($sale['productos'] as &$sp) {
                if ($code === '' || strcasecmp((string)($sp['codigo'] ?? ''), (string)$code) === 0) {
                    $sp['cantidad'] = $newQuantity;
                    if ($price !== null) $sp['precio'] = $price;
                }
            }
            unset($sp);
        }
        $unitPrice = $price !== null ? $price : (float)($sale['productos'][0]['precio'] ?? 0);
        $newTotal = round($newQuantity * $unitPrice, 2);
        $sale['total'] = $newTotal;
        $sale['dinero'] = $newTotal;
        break;
    }
    unset($sale);
    writeJsonFile($ventasFile, $sales);

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

    if ($db !== null) {
        try {
            $db->beginTransaction();

            $find = $db->prepare("
                SELECT f.`ID_factura`, f.`cantidadVendida`, f.`ID_stock`, f.`precioFinal`, p.`nombre`, p.`codigo`
                FROM `facturacion` f
                LEFT JOIN `producto` p ON p.`ID_stock` = f.`ID_stock`
                WHERE f.`ID_factura` = :id
                FOR UPDATE
            ");
            $find->execute([':id' => $idInt]);
            $row = $find->fetch();

            if ($row) {
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

                // Eliminar registro de facturacion
                $stmtDel = $db->prepare("DELETE FROM `facturacion` WHERE `ID_factura` = :id");
                $stmtDel->execute([':id' => $idInt]);
            }

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Error al eliminar venta en DB: ' . $e->getMessage());
        }
    }

    // Obtener detalles de ventas.json si no vinieron de MySQL
    $sales = readJsonFile($ventasFile);
    if ($deletedQty === 0) {
        foreach ($sales as $sale) {
            if ((string)($sale['id'] ?? '') === (string)$id || (int)($sale['ID_factura'] ?? 0) === $idInt) {
                if (!empty($sale['productos']) && is_array($sale['productos'])) {
                    $p0 = $sale['productos'][0];
                    $deletedQty = (int)($p0['cantidad'] ?? 1);
                    $deletedProdId = (int)($p0['ID_stock'] ?? ($p0['id'] ?? 0));
                    $deletedProdCode = (string)($p0['codigo'] ?? '');
                    $deletedProdName = (string)($p0['nombre'] ?? 'Producto');
                }
                break;
            }
        }
    }

    // Restaurar stock en inventario.json
    if ($deletedQty > 0 || $deletedProdCode !== '') {
        $inventory = readJsonFile($inventarioFile);
        foreach ($inventory as &$item) {
            $matchId = $deletedProdId > 0 && (int)($item['ID_stock'] ?? ($item['id'] ?? 0)) === $deletedProdId;
            $matchCode = $deletedProdCode !== '' && strcasecmp((string)($item['codigo'] ?? ''), $deletedProdCode) === 0;
            if ($matchId || $matchCode) {
                $prevStock = (int)($item['cantidad'] ?? ($item['stock'] ?? 0));
                $prevSold = (int)($item['cantVendida'] ?? 0);
                $newStock = $prevStock + $deletedQty;
                $item['cantidad'] = $newStock;
                if (isset($item['stock'])) $item['stock'] = $newStock;
                $item['cantVendida'] = max(0, $prevSold - $deletedQty);
                $item['total'] = round($newStock * (float)($item['precio'] ?? 0), 2);
                break;
            }
        }
        unset($item);
        writeJsonFile($inventarioFile, $inventory);
    }

    // Eliminar de ventas.json
    $sales = array_values(array_filter($sales, function ($v) use ($id, $idInt) {
        return (string)($v['id'] ?? '') !== (string)$id && (int)($v['ID_factura'] ?? 0) !== $idInt;
    }));
    writeJsonFile($ventasFile, $sales);

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
