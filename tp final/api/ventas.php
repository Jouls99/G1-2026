<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

handleOptions();

$ventasFile = dataPath('ventas.json');
$inventarioFile = dataPath('inventario.json');
$method = requestMethod();
$db = getDBConnection();

// GET: Listar ventas desde la base de datos MySQL (tabla `facturacion` y `producto`)
if ($method === 'GET') {
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

        // Agrupar facturaciones en ventas compatibles con el formato de la UI
        $ventas = [];
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

        // Si no hay ventas en DB pero sí en ventas.json, devolver las del JSON
        if (empty($ventas) && file_exists($ventasFile)) {
            $jsonVentas = readJsonFile($ventasFile);
            if (!empty($jsonVentas)) {
                sendJson($jsonVentas);
            }
        }

        sendJson($ventas);
    } catch (Exception $e) {
        $ventas = readJsonFile($ventasFile);
        sendJson($ventas);
    }
}

// POST: Registrar una nueva venta en MySQL (tabla `facturacion` y actualización en `producto`)
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

    try {
        $db->beginTransaction();

        $fechaSql = date('Y-m-d');
        if (!empty($payload['fecha'])) {
            $ts = strtotime((string)$payload['fecha']);
            if ($ts !== false) {
                $fechaSql = date('Y-m-d', $ts);
            }
        }

        $stmtFindProd = $db->prepare("
            SELECT `ID_stock`, `cantTotal`, `cantVendida`, `precio`, `nombre`
            FROM `producto`
            WHERE LOWER(`codigo`) = LOWER(:codigo) OR `ID_stock` = :id
            LIMIT 1
        ");

        $usuarioVendedor = $_SESSION['user']['usuario'] ?? 'gomez11';
        $userIdVendedor = $_SESSION['user']['id'] ?? null;

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

        $totalCalculado = 0;
        $productosVenta = [];
        $facturasIds = [];

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
                // Si no existe, crear producto en DB
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

            $totalCalculado += $precio * $cantidad;

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

        $totalVenta = (float)($payload['total'] ?? $totalCalculado);
        $ventaResult = [
            'id'          => (string)($facturasIds[0] ?? time()),
            'factura_ids' => $facturasIds,
            'usuario'     => $usuarioVendedor,
            'productos'   => $productosVenta,
            'total'       => $totalVenta,
            'dinero'      => (float)($payload['dinero'] ?? $totalVenta),
            'fecha'       => date('c')
        ];

        // Sincronizar archivo JSON de ventas como respaldo
        $ventas = readJsonFile($ventasFile);
        $ventas[] = $ventaResult;
        writeJsonFile($ventasFile, $ventas);

        // Registrar interacción de venta
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

        sendJson(['ok' => true, 'venta' => $ventaResult]);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        sendJson(['error' => 'save_error', 'message' => 'Error al registrar venta en MySQL: ' . $e->getMessage()], 500);
    }
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

    try {
        $idInt = (int)$id;
        $stmt = $db->prepare("DELETE FROM `facturacion` WHERE `ID_factura` = :id");
        $stmt->execute([':id' => $idInt]);

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

        sendJson(['ok' => true, 'message' => 'Venta eliminada correctamente de la base de datos.']);
    } catch (Exception $e) {
        sendJson(['error' => 'delete_error', 'message' => 'Error al eliminar venta: ' . $e->getMessage()], 500);
    }
}

sendJson(['error' => 'method_not_allowed'], 405);

