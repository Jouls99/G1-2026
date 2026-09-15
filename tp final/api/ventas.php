<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

handleOptions();

$ventasFile = dataPath('ventas.json');
$inventarioFile = dataPath('inventario.json');
$method = requestMethod();
$db = getDBConnection();

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
                SET `cantTotal` = GREATEST(0, `cantTotal` - :cantidad),
                    `cantVendida` = COALESCE(`cantVendida`, 0) + :cantidad
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
                    ':cantidad' => $cantidad,
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

// PUT: Actualizar lista de ventas o modificar una venta existente
if ($method === 'PUT') {
    $payload = getJsonBody();

    if (!is_array($payload)) {
        sendJson(['error' => 'invalid_payload', 'message' => 'El cuerpo debe ser un array de ventas o un objeto venta.'], 400);
    }

    // Si es un array de ventas
    if (isset($payload[0]) || empty($payload)) {
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
    $id = (string)($payload['id'] ?? '');
    if ($id !== '') {
        $ventas = readJsonFile($ventasFile);
        $found = false;
        foreach ($ventas as &$v) {
            if ((string)($v['id'] ?? '') === $id) {
                $v = array_merge($v, $payload);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $ventas[] = $payload;
        }
        writeJsonFile($ventasFile, $ventas);

        $currentUser = getApiUser();
        $uName = $currentUser['usuario'] ?? 'Administrador';
        logActivity(
            $uName,
            'venta_modificada',
            "Venta #{$id} modificada por {$uName}",
            ['id' => $id, 'total' => $payload['total'] ?? null],
            $currentUser['id'] ?? null
        );

        sendJson(['ok' => true, 'message' => 'Venta actualizada correctamente.']);
    }

    sendJson(['error' => 'invalid_payload', 'message' => 'Falta el ID de la venta.'], 400);
}

// DELETE: Eliminar una venta / factura por ID
if ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) {
        $body = getJsonBody();
        $id = $body['id'] ?? null;
    }

    if (!$id) {
        sendJson(['error' => 'missing_id', 'message' => 'Se requiere el parámetro ID de la venta.'], 400);
    }

    $idInt = (int)$id;

    if ($db !== null) {
        try {
            $stmt = $db->prepare("DELETE FROM `facturacion` WHERE `ID_factura` = :id");
            $stmt->execute([':id' => $idInt]);
        } catch (Exception $e) {
            // Continuar con JSON
        }
    }

    // Sincronizar JSON
    $ventas = readJsonFile($ventasFile);
    $ventas = array_values(array_filter($ventas, function ($v) use ($id) {
        return (string)($v['id'] ?? '') !== (string)$id;
    }));
    writeJsonFile($ventasFile, $ventas);

    $currentUser = getApiUser();
    $uName = $currentUser['usuario'] ?? 'Administrador';
    logActivity(
        $uName,
        'venta_eliminada',
        "Venta / Factura #{$id} eliminada por {$uName}",
        ['id_factura' => $id],
        $currentUser['id'] ?? null
    );

    sendJson(['ok' => true, 'message' => 'Venta eliminada correctamente.']);
}

sendJson(['error' => 'method_not_allowed'], 405);
