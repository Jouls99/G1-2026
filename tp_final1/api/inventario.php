<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

handleOptions();

$file = dataPath('inventario.json');
$method = requestMethod();
$db = getDBConnection();
$superAdmin = isSuperAdminApi();

requireApiAuth();

// GET: Obtener inventario desde la base de datos MySQL (tabla `producto` y `categoria`) o fallback a JSON
if ($method === 'GET') {
    if (($_GET['action'] ?? '') === 'price_history') {
        requireAdminApi();
        if ($db === null) {
            sendJson(['ok' => false, 'message' => 'El historial de ajustes requiere conexión a la base de datos.'], 503);
        }

        try {
            $historyStmt = $db->query("
                SELECT `fecha`, `usuario`, `alcance`, `categoria`, `tipo`, `nombre_producto`,
                       `tipo_ajuste`, `valor`, `cantidad_productos`
                FROM `historial_ajuste_precio`
                ORDER BY `id_ajuste` DESC
                LIMIT 100
            ");
            $history = array_map(static function (array $row): array {
                return [
                    'fecha' => date('c', strtotime((string)$row['fecha'])),
                    'usuario' => (string)$row['usuario'],
                    'alcance' => (string)$row['alcance'],
                    'categoria' => $row['categoria'],
                    'tipo' => $row['tipo'],
                    'nombre' => $row['nombre_producto'],
                    'tipo_ajuste' => (string)$row['tipo_ajuste'],
                    'valor' => (float)$row['valor'],
                    'cantidad' => (int)$row['cantidad_productos']
                ];
            }, $historyStmt->fetchAll());
            sendJson(['ok' => true, 'history' => $history]);
        } catch (Exception $e) {
            error_log('Error al consultar el historial de ajustes de precios: ' . $e->getMessage());
            sendJson(['ok' => false, 'message' => 'No se pudo cargar el historial de ajustes.'], 500);
        }
    }

    if (($_GET['action'] ?? '') === 'audit') {
        requireSuperAdminApi();
        if ($db === null) {
            sendJson(['ok' => false, 'message' => 'La auditoría no está disponible sin conexión a la base de datos.'], 503);
        }

        try {
            $auditStmt = $db->query("
                SELECT `id_actividad`, `usuario`, `tipo_accion`, `descripcion`, `detalles`, `fecha`
                FROM `actividad_usuario`
                WHERE `tipo_accion` IN ('stock_habilitar', 'stock_deshabilitar')
                ORDER BY `id_actividad` DESC
                LIMIT 250
            ");
            $audit = array_map(static function (array $row): array {
                $details = null;
                if (!empty($row['detalles'])) {
                    $decoded = json_decode((string)$row['detalles'], true);
                    $details = is_array($decoded) ? $decoded : $row['detalles'];
                }
                return [
                    'id' => (int)$row['id_actividad'],
                    'usuario' => (string)$row['usuario'],
                    'tipo' => (string)$row['tipo_accion'],
                    'descripcion' => (string)$row['descripcion'],
                    'detalles' => $details,
                    'fecha' => date('c', strtotime((string)$row['fecha']))
                ];
            }, $auditStmt->fetchAll());
            sendJson(['ok' => true, 'auditoria' => $audit]);
        } catch (Exception $e) {
            error_log('Auditoría MySQL no disponible, se usa actividades.json: ' . $e->getMessage());
            $events = readJsonFile(dataPath('actividades.json'));
            $audit = [];
            foreach (array_reverse($events) as $event) {
                if (!in_array((string)($event['tipo_accion'] ?? ''), ['stock_habilitar', 'stock_deshabilitar'], true)) {
                    continue;
                }
                $audit[] = [
                    'id' => (int)($event['id'] ?? 0),
                    'usuario' => (string)($event['usuario'] ?? 'Sistema'),
                    'tipo' => (string)$event['tipo_accion'],
                    'descripcion' => (string)($event['descripcion'] ?? ''),
                    'detalles' => is_array($event['detalles'] ?? null) ? $event['detalles'] : null,
                    'fecha' => (string)($event['fecha'] ?? '')
                ];
                if (count($audit) >= 250) break;
            }
            sendJson(['ok' => true, 'auditoria' => $audit, 'fuente' => 'respaldo']);
        }
    }

    try {
        $stmt = $db->query("
            SELECT 
                p.ID_stock,
                p.nombre,
                COALESCE(p.codigo, CONCAT('PROD-', p.ID_stock)) AS codigo,
                COALESCE(p.cantTotal, 0) AS cantidad,
                COALESCE(p.cantVendida, 0) AS cantVendida,
                CAST(COALESCE(p.precio, 0) AS DECIMAL(10,2)) AS precio,
                ROUND(COALESCE(p.cantTotal, 0) * COALESCE(p.precio, 0), 2) AS total,
                COALESCE(c.nombre, 'General') AS categoria,
                p.ID_categoria,
                " . ($superAdmin ? "COALESCE(p.fase, 'habilitado') AS fase," : "") . "
                p.subcategoria
            FROM `producto` p
            LEFT JOIN `categoria` c ON p.ID_categoria = c.ID_categoria
            " . ($superAdmin ? "" : "WHERE COALESCE(p.fase, 'habilitado') = 'habilitado'") . "
            ORDER BY p.ID_stock ASC
        ");
        $productos = $stmt->fetchAll();

        // Convertir tipos numéricos y estructurar con campo fase
        $normalized = array_map(function ($p) use ($superAdmin) {
            $producto = [
                'id'           => (int)$p['ID_stock'],
                'ID_stock'     => (int)$p['ID_stock'],
                'nombre'       => (string)$p['nombre'],
                'codigo'       => (string)$p['codigo'],
                'cantidad'     => (int)$p['cantidad'],
                'cantVendida'  => (int)$p['cantVendida'],
                'precio'       => (float)$p['precio'],
                'total'        => (float)$p['total'],
                'categoria'    => (string)$p['categoria'],
                'ID_categoria' => $p['ID_categoria'] !== null ? (int)$p['ID_categoria'] : null,
                'subcategoria' => $p['subcategoria'] !== null ? (string)$p['subcategoria'] : null
            ];

            if ($superAdmin) {
                $fase = strtolower(trim((string)($p['fase'] ?? 'habilitado')));
                $producto['fase'] = $fase !== '' ? $fase : 'habilitado';
            }

            return $producto;
        }, $productos);

        // Guardar copia de seguridad en JSON
        writeJsonFile($file, $normalized);

        sendJson($normalized);
    } catch (Exception $e) {
        // Fallback a JSON si hubiese algún problema temporal
        $inventario = readJsonFile($file);
        if (!$superAdmin) {
            $inventario = array_values(array_filter($inventario, static function ($p) {
                return ($p['fase'] ?? 'habilitado') !== 'deshabilitado';
            }));
        }
        $safe = array_map(function ($p) use ($superAdmin) {
            if ($superAdmin) {
                $p['fase'] = strtolower(trim((string)($p['fase'] ?? 'habilitado')));
                if ($p['fase'] === '') $p['fase'] = 'habilitado';
            } else {
                unset($p['fase']);
            }
            return $p;
        }, $inventario);
        sendJson($safe);
    }
}

// POST: Agregar un nuevo producto al inventario en MySQL (o rehabilitar si se especifica action=rehabilitar)
if ($method === 'POST') {
    $body = getJsonBody();
    $action = $_GET['action'] ?? ($body['action'] ?? 'create');

    if ($action === 'adjust_prices') {
        requireAdminApi();
        if ($db === null) {
            sendJson(['ok' => false, 'message' => 'Los ajustes de precios requieren conexión a la base de datos.'], 503);
        }
        if (!is_array($body)) {
            sendJson(['ok' => false, 'message' => 'La solicitud de ajuste no es válida.'], 400);
        }

        $scope = (string)($body['scope'] ?? '');
        $adjustmentType = (string)($body['adjustmentType'] ?? '');
        $adjustmentValue = filter_var($body['adjustmentValue'] ?? null, FILTER_VALIDATE_FLOAT);
        $categoryId = filter_var($body['categoryId'] ?? null, FILTER_VALIDATE_INT);
        $type = trim((string)($body['type'] ?? ''));
        $name = trim((string)($body['name'] ?? ''));
        $validScopes = ['category', 'type', 'name', 'selected'];
        if ($adjustmentValue !== false) {
            $adjustmentValue = round((float)$adjustmentValue, 2);
        }

        if (!in_array($scope, $validScopes, true)
            || !in_array($adjustmentType, ['percentage', 'fixed'], true)
            || $adjustmentValue === false
            || !is_finite((float)$adjustmentValue)
            || (float)$adjustmentValue === 0.0
            || ($adjustmentType === 'percentage' && (float)$adjustmentValue <= -100)
            || abs((float)$adjustmentValue) > 100000000) {
            sendJson(['ok' => false, 'message' => 'El tipo o valor del ajuste no es válido.'], 400);
        }
        if (in_array($scope, ['category', 'type'], true) && (!$categoryId || $categoryId < 1)) {
            sendJson(['ok' => false, 'message' => 'Seleccioná una categoría válida.'], 400);
        }
        if ($scope === 'type' && $type === '') {
            sendJson(['ok' => false, 'message' => 'Seleccioná un tipo de producto.'], 400);
        }
        if ($scope === 'name' && $name === '') {
            sendJson(['ok' => false, 'message' => 'Ingresá el nombre exacto del producto.'], 400);
        }

        $where = [];
        $params = [];
        if ($scope === 'category' || $scope === 'type') {
            $where[] = 'p.`ID_categoria` = :category_id';
            $params[':category_id'] = $categoryId;
        }
        if ($scope === 'type') {
            $where[] = 'p.`subcategoria` = :subcategory';
            $params[':subcategory'] = $type;
        } elseif ($scope === 'name') {
            $where[] = 'LOWER(TRIM(p.`nombre`)) = LOWER(TRIM(:product_name))';
            $params[':product_name'] = $name;
        } elseif ($scope === 'selected') {
            $productIds = $body['productIds'] ?? null;
            if (!is_array($productIds) || count($productIds) === 0 || count($productIds) > 500) {
                sendJson(['ok' => false, 'message' => 'Seleccioná entre 1 y 500 productos.'], 400);
            }
            foreach ($productIds as $productId) {
                if (filter_var($productId, FILTER_VALIDATE_INT) === false || (int)$productId < 1) {
                    sendJson(['ok' => false, 'message' => 'La selección contiene un identificador de producto no válido.'], 400);
                }
            }
            $productIds = array_values(array_unique(array_map('intval', $productIds)));
            if (count($productIds) === 0) {
                sendJson(['ok' => false, 'message' => 'La selección no contiene productos válidos.'], 400);
            }
            $placeholders = [];
            foreach ($productIds as $index => $id) {
                $placeholder = ':product_id_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = (int)$id;
            }
            $where[] = 'p.`ID_stock` IN (' . implode(', ', $placeholders) . ')';
        }

        try {
            $db->beginTransaction();
            $selectSql = "
                SELECT p.`ID_stock`, p.`nombre`, p.`precio`, p.`subcategoria`,
                       COALESCE(c.`nombre`, 'Sin categoría') AS categoria
                FROM `producto` p
                LEFT JOIN `categoria` c ON c.`ID_categoria` = p.`ID_categoria`
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.`ID_stock`
                FOR UPDATE
            ";
            $selectStmt = $db->prepare($selectSql);
            $selectStmt->execute($params);
            $products = $selectStmt->fetchAll();
            if ($scope === 'selected' && count($products) !== count($productIds)) {
                $db->rollBack();
                sendJson(['ok' => false, 'message' => 'Uno o más productos seleccionados ya no existen. No se modificó ningún precio.'], 409);
            }
            if (!$products) {
                $db->rollBack();
                sendJson(['ok' => false, 'message' => 'No se encontraron productos para aplicar el ajuste.'], 404);
            }

            $updateStmt = $db->prepare("UPDATE `producto` SET `precio` = :precio WHERE `ID_stock` = :id");
            $categories = [];
            $types = [];
            foreach ($products as $product) {
                $oldPrice = (float)$product['precio'];
                $newPrice = $adjustmentType === 'percentage'
                    ? round($oldPrice * (1 + (float)$adjustmentValue / 100), 2)
                    : round($oldPrice + (float)$adjustmentValue, 2);
                if ($newPrice < 0 || $newPrice > 99999999.99) {
                    $db->rollBack();
                    sendJson(['ok' => false, 'message' => 'El ajuste produciría un precio negativo o fuera del rango permitido. No se modificó ningún precio.'], 422);
                }
                $updateStmt->execute([':precio' => number_format($newPrice, 2, '.', ''), ':id' => (int)$product['ID_stock']]);
                $categories[(string)$product['categoria']] = true;
                if (!empty($product['subcategoria'])) {
                    $types[(string)$product['subcategoria']] = true;
                }
            }

            $currentUser = getApiUser();
            $historyStmt = $db->prepare("
                INSERT INTO `historial_ajuste_precio`
                    (`usuario`, `alcance`, `categoria`, `tipo`, `nombre_producto`, `tipo_ajuste`, `valor`, `cantidad_productos`)
                VALUES (:usuario, :alcance, :categoria, :tipo, :nombre, :tipo_ajuste, :valor, :cantidad)
            ");
            $historyStmt->execute([
                ':usuario' => (string)($currentUser['usuario'] ?? $currentUser['nombre'] ?? 'Administrador'),
                ':alcance' => $scope,
                ':categoria' => implode(', ', array_keys($categories)),
                ':tipo' => $scope === 'type' ? $type : implode(', ', array_keys($types)),
                ':nombre' => $scope === 'name' ? $name : null,
                ':tipo_ajuste' => $adjustmentType,
                ':valor' => number_format((float)$adjustmentValue, 2, '.', ''),
                ':cantidad' => count($products)
            ]);
            $db->commit();

            $inventory = readJsonFile($file);
            $updatedById = [];
            foreach ($products as $product) {
                $updatedById[(int)$product['ID_stock']] = true;
            }
            foreach ($inventory as &$item) {
                $id = (int)($item['ID_stock'] ?? ($item['id'] ?? 0));
                if (isset($updatedById[$id])) {
                    $oldPrice = (float)($item['precio'] ?? 0);
                    $item['precio'] = $adjustmentType === 'percentage'
                        ? round($oldPrice * (1 + (float)$adjustmentValue / 100), 2)
                        : round($oldPrice + (float)$adjustmentValue, 2);
                    $item['total'] = $item['precio'] * (int)($item['cantidad'] ?? $item['stock'] ?? 0);
                }
            }
            unset($item);
            writeJsonFile($file, $inventory);

            sendJson(['ok' => true, 'updated' => count($products)]);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Error al aplicar ajuste de precios: ' . $e->getMessage());
            sendJson(['ok' => false, 'message' => 'No se pudo aplicar el ajuste de precios.'], 500);
        }
    }

    requireStockRegistrationApi();
    if ($action !== 'create' && !isAdminApi()) {
        sendJson(['ok' => false, 'error' => 'forbidden', 'message' => 'Este permiso solo permite registrar productos nuevos.'], 403);
    }

    // Acción para rehabilitar producto
    if ($action === 'rehabilitar') {
        $codigo = trim((string)($body['codigo'] ?? ($_GET['codigo'] ?? '')));
        if ($codigo === '') {
            sendJson(['error' => 'missing_code', 'message' => 'Se requiere el código del producto.'], 400);
        }

        if ($db !== null) {
            try {
                $updStmt = $db->prepare("UPDATE `producto` SET `fase` = 'habilitado' WHERE LOWER(`codigo`) = LOWER(:codigo) OR `ID_stock` = :id");
                $updStmt->execute([
                    ':codigo' => $codigo,
                    ':id'     => is_numeric($codigo) ? (int)$codigo : 0
                ]);
            } catch (Exception $e) {
                // Ignorar
            }
        }

        // Sincronizar en JSON
        $inventario = readJsonFile($file);
        foreach ($inventario as &$item) {
            $matchCode = strcasecmp((string)($item['codigo'] ?? ''), $codigo) === 0;
            $matchId = isset($item['ID_stock']) && is_numeric($codigo) && (int)$item['ID_stock'] === (int)$codigo;
            if ($matchCode || $matchId) {
                $item['fase'] = 'habilitado';
            }
        }
        unset($item);
        writeJsonFile($file, $inventario);

        $currentUser = getApiUser();
        $uName = $currentUser['usuario'] ?? 'Administrador';
        logActivity(
            $uName,
            'stock_habilitar',
            "Producto '{$codigo}' rehabilitado (fase: habilitado) por {$uName}",
            ['codigo' => $codigo, 'fase' => 'habilitado'],
            $currentUser['id'] ?? null
        );

        sendJson(['ok' => true, 'message' => "Producto '{$codigo}' rehabilitado exitosamente.", 'fase' => 'habilitado']);
    }

    if (!is_array($body) || empty($body['nombre']) || empty($body['codigo'])) {
        sendJson(['error' => 'invalid_payload', 'message' => 'Faltan campos obligatorios (nombre, codigo).'], 400);
    }

    $nombre = trim((string)$body['nombre']);
    $codigo = trim((string)$body['codigo']);
    $precio = (float)($body['precio'] ?? 0);
    $cantidad = (int)($body['cantidad'] ?? ($body['stock'] ?? 0));
    $categoriaName = trim((string)($body['categoria'] ?? 'General'));
    $fase = 'habilitado';
    if ($categoriaName === '') {
        $categoriaName = 'General';
    }

    if ($db === null) {
        $inventory = readJsonFile($file);
        foreach ($inventory as &$item) {
            if (strcasecmp((string)($item['codigo'] ?? ''), $codigo) !== 0) continue;
            if (strtolower((string)($item['fase'] ?? 'habilitado')) !== 'deshabilitado') {
                unset($item);
                sendJson(['error' => 'item_exists', 'message' => 'Ya existe un producto activo con este código.'], 409);
            }
            if (!isAdminApi()) {
                unset($item);
                sendJson(['ok' => false, 'error' => 'forbidden', 'message' => 'Solo un administrador puede rehabilitar productos deshabilitados.'], 403);
            }
            $item['nombre'] = $nombre;
            $item['precio'] = $precio;
            $item['cantidad'] = $cantidad;
            $item['fase'] = 'habilitado';
            writeJsonFile($file, $inventory);
            sendJson(['ok' => true, 'message' => 'Producto existente rehabilitado y actualizado.', 'item' => $item]);
        }
        unset($item);

        $newId = 1;
        foreach ($inventory as $item) {
            $newId = max($newId, (int)($item['ID_stock'] ?? ($item['id'] ?? 0)) + 1);
        }
        $newItem = [
            'id' => $newId,
            'ID_stock' => $newId,
            'categoria' => $categoriaName,
            'nombre' => $nombre,
            'codigo' => $codigo,
            'precio' => $precio,
            'cantidad' => $cantidad,
            'cantVendida' => 0,
            'total' => $precio * $cantidad,
            'fase' => 'habilitado',
            'subcategoria' => !empty($body['subcategoria']) ? trim((string)$body['subcategoria']) : null
        ];
        $inventory[] = $newItem;
        writeJsonFile($file, $inventory);

        $currentUser = getApiUser();
        $userName = (string)($currentUser['usuario'] ?? 'Administrador');
        logActivity($userName, 'stock_crear', "Nuevo producto '{$nombre}' (Cód: {$codigo}) agregado con stock {$cantidad}, precio {$precio}", $newItem, isset($currentUser['id']) ? (int)$currentUser['id'] : null);
        sendJson(['ok' => true, 'item' => $newItem]);
    }

    try {
        // Verificar si el código ya existe
        $checkStmt = $db->prepare("SELECT `ID_stock`, `fase` FROM `producto` WHERE LOWER(`codigo`) = LOWER(:codigo) LIMIT 1");
        $checkStmt->execute([':codigo' => $codigo]);
        $existing = $checkStmt->fetch();
        if ($existing) {
            // Si existía pero estaba deshabilitado, actualizar y rehabilitar
            if (strtolower((string)($existing['fase'] ?? '')) === 'deshabilitado') {
                if (!isAdminApi()) {
                    sendJson(['ok' => false, 'error' => 'forbidden', 'message' => 'Solo un administrador puede rehabilitar productos deshabilitados.'], 403);
                }
                $updRehab = $db->prepare("UPDATE `producto` SET `nombre` = :nom, `cantTotal` = :cant, `precio` = :prec, `subcategoria` = :subcategoria, `fase` = 'habilitado' WHERE `ID_stock` = :id");
                $updRehab->execute([
                    ':nom'  => $nombre,
                    ':cant' => $cantidad,
                    ':prec' => $precio,
                    ':subcategoria' => !empty($body['subcategoria']) ? trim((string)$body['subcategoria']) : null,
                    ':id'   => (int)$existing['ID_stock']
                ]);

                $inventario = readJsonFile($file);
                foreach ($inventario as &$item) {
                    if (strcasecmp((string)($item['codigo'] ?? ''), $codigo) === 0) {
                        $item['nombre'] = $nombre;
                        $item['precio'] = $precio;
                        $item['cantidad'] = $cantidad;
                        $item['subcategoria'] = !empty($body['subcategoria']) ? trim((string)$body['subcategoria']) : null;
                        $item['fase'] = 'habilitado';
                    }
                }
                unset($item);
                writeJsonFile($file, $inventario);

                sendJson(['ok' => true, 'message' => 'Producto existente rehabilitado y actualizado.', 'item' => ['codigo' => $codigo, 'fase' => 'habilitado']]);
            }
            sendJson(['error' => 'item_exists', 'message' => 'Ya existe un producto activo con este código.'], 409);
        }

        // Obtener o crear la categoría
        $catStmt = $db->prepare("SELECT `ID_categoria` FROM `categoria` WHERE LOWER(`nombre`) = LOWER(:nombre) LIMIT 1");
        $catStmt->execute([':nombre' => $categoriaName]);
        $catId = $catStmt->fetchColumn();

        if (!$catId) {
            $insertCat = $db->prepare("INSERT INTO `categoria` (`nombre`) VALUES (:nombre)");
            $insertCat->execute([':nombre' => $categoriaName]);
            $catId = (int)$db->lastInsertId();
        } else {
            $catId = (int)$catId;
        }

        // Insertar en tabla producto con fase habilitado
        $insertProd = $db->prepare("
            INSERT INTO `producto` (`nombre`, `codigo`, `cantTotal`, `cantVendida`, `ID_categoria`, `subcategoria`, `precio`, `fase`)
            VALUES (:nombre, :codigo, :cantTotal, 0, :ID_categoria, :subcategoria, :precio, 'habilitado')
        ");
        $insertProd->execute([
            ':nombre'       => $nombre,
            ':codigo'       => $codigo,
            ':cantTotal'    => $cantidad,
            ':ID_categoria' => $catId,
            ':subcategoria' => !empty($body['subcategoria']) ? trim((string)$body['subcategoria']) : null,
            ':precio'       => $precio
        ]);

        $newId = (int)$db->lastInsertId();

        $nuevoItem = [
            'id'           => $newId,
            'ID_stock'     => $newId,
            'categoria'    => $categoriaName,
            'ID_categoria' => $catId,
            'nombre'       => $nombre,
            'codigo'       => $codigo,
            'precio'       => $precio,
            'cantidad'     => $cantidad,
            'cantVendida'  => 0,
            'total'        => $precio * $cantidad,
            'fase'         => 'habilitado',
            'subcategoria' => !empty($body['subcategoria']) ? trim((string)$body['subcategoria']) : null
        ];

        // Sincronizar archivo JSON
        $inventario = readJsonFile($file);
        $inventario[] = $nuevoItem;
        writeJsonFile($file, $inventario);

        $currentUser = getApiUser();
        $uName = $currentUser['usuario'] ?? 'Administrador';
        logActivity(
            $uName,
            'stock_crear',
            "Nuevo producto '{$nombre}' (Cód: {$codigo}) agregado con stock {$cantidad}, precio \${$precio} (fase: habilitado)",
            $nuevoItem,
            $currentUser['id'] ?? null
        );

        sendJson(['ok' => true, 'item' => $nuevoItem]);
    } catch (Exception $e) {
        error_log('Error al guardar producto: ' . $e->getMessage());
        sendJson(['error' => 'save_error', 'message' => 'No se pudo guardar el producto.'], 500);
    }
}

// PUT: Actualizar producto o inventario en MySQL
if ($method === 'PUT') {
    requireAdminApi();
    $payload = getJsonBody();

    if (!is_array($payload)) {
        sendJson(['error' => 'invalid_payload', 'message' => 'El cuerpo debe ser un array de productos o un objeto.'], 400);
    }

    try {
        // Si viene un array de productos
        if (isset($payload[0]) || empty($payload)) {
            $updateStmt = $db->prepare("
                UPDATE `producto` 
                SET `cantTotal` = :cant, `precio` = :precio, `nombre` = :nombre, `subcategoria` = :subcategoria,
                    `fase` = COALESCE(:fase, `fase`, 'habilitado')
                WHERE LOWER(`codigo`) = LOWER(:codigo) OR `ID_stock` = :id
            ");

            foreach ($payload as $item) {
                if (!is_array($item) || empty($item['codigo'])) {
                    continue;
                }
                $codigo = trim((string)$item['codigo']);
                $precio = (float)($item['precio'] ?? 0);
                $cantidad = (int)($item['cantidad'] ?? ($item['stock'] ?? 0));
                $nombre = trim((string)($item['nombre'] ?? ''));
                $subcategoria = !empty($item['subcategoria']) ? trim((string)$item['subcategoria']) : null;
                $id = (int)($item['ID_stock'] ?? ($item['id'] ?? 0));
                $faseItem = isset($item['fase']) ? strtolower(trim((string)$item['fase'])) : null;

                $updateStmt->execute([
                    ':cant'   => $cantidad,
                    ':precio' => $precio,
                    ':nombre' => $nombre,
                    ':subcategoria' => $subcategoria,
                    ':codigo' => $codigo,
                    ':id'     => $id,
                    ':fase'   => $faseItem
                ]);
            }

            writeJsonFile($file, $payload);
            sendJson(['ok' => true, 'count' => count($payload)]);
        }

        // Si es un solo producto
        $codigo = trim((string)($payload['codigo'] ?? ''));
        $id = (int)($payload['ID_stock'] ?? ($payload['id'] ?? 0));
        $precio = (float)($payload['precio'] ?? 0);
        $cantidad = (int)($payload['cantidad'] ?? ($payload['stock'] ?? 0));
        $nombre = trim((string)($payload['nombre'] ?? ''));
        $subcategoria = array_key_exists('subcategoria', $payload)
            ? (trim((string)$payload['subcategoria']) !== '' ? trim((string)$payload['subcategoria']) : null)
            : null;
        $fase = isset($payload['fase']) ? strtolower(trim((string)$payload['fase'])) : null;

        $updates = [];
        $params = [':codigo' => $codigo, ':id' => $id];

        if ($nombre !== '') {
            $updates[] = "`nombre` = :nombre";
            $params[':nombre'] = $nombre;
        }
        if (array_key_exists('subcategoria', $payload)) {
            $updates[] = "`subcategoria` = :subcategoria";
            $params[':subcategoria'] = $subcategoria;
        }
        if (isset($payload['precio'])) {
            $updates[] = "`precio` = :precio";
            $params[':precio'] = $precio;
        }
        if (isset($payload['cantidad']) || isset($payload['stock'])) {
            $updates[] = "`cantTotal` = :cant";
            $params[':cant'] = $cantidad;
        }
        if ($fase !== null && in_array($fase, ['habilitado', 'deshabilitado'], true)) {
            $updates[] = "`fase` = :fase";
            $params[':fase'] = $fase;
        }

        if (!empty($updates)) {
            $sql = "UPDATE `producto` SET " . implode(', ', $updates) . " WHERE LOWER(`codigo`) = LOWER(:codigo) OR `ID_stock` = :id";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
        }

        // Sincronizar en JSON
        $inventario = readJsonFile($file);
        foreach ($inventario as &$item) {
            $matchCode = strcasecmp((string)($item['codigo'] ?? ''), $codigo) === 0;
            $matchId = isset($item['ID_stock']) && $id > 0 && (int)$item['ID_stock'] === $id;
            if ($matchCode || $matchId) {
                if ($nombre !== '') $item['nombre'] = $nombre;
                if (array_key_exists('subcategoria', $payload)) $item['subcategoria'] = $subcategoria;
                if (isset($payload['precio'])) $item['precio'] = $precio;
                if (isset($payload['cantidad']) || isset($payload['stock'])) $item['cantidad'] = $cantidad;
                if ($fase !== null) $item['fase'] = $fase;
            }
        }
        unset($item);
        writeJsonFile($file, $inventario);

        $currentUser = getApiUser();
        $uName = $currentUser['usuario'] ?? 'Administrador';
        logActivity(
            $uName,
            'stock_modificar',
            "Stock modificado para producto '{$nombre}' (Cód: {$codigo})" . ($fase ? " [Fase: {$fase}]" : ""),
            ['codigo' => $codigo, 'cantidad' => $cantidad, 'precio' => $precio, 'fase' => $fase],
            $currentUser['id'] ?? null
        );

        sendJson(['ok' => true, 'message' => 'Producto actualizado correctamente en base de datos.']);
    } catch (Exception $e) {
        error_log('Error al actualizar inventario: ' . $e->getMessage());
        sendJson(['error' => 'save_error', 'message' => 'No se pudo actualizar el inventario.'], 500);
    }
}

// DELETE: Deshabilitar producto (Baja lógica / Soft-delete a fase = 'deshabilitado')
if ($method === 'DELETE') {
    requireAdminApi();
    $codigo = $_GET['codigo'] ?? null;
    if (!$codigo) {
        $body = getJsonBody();
        $codigo = $body['codigo'] ?? null;
    }

    if (!$codigo) {
        sendJson(['error' => 'missing_code', 'message' => 'Se requiere el parámetro codigo.'], 400);
    }

    $updatedInDb = false;
    $updatedInJson = false;
    $productName = (string)$codigo;

    // 1. Marcar como deshabilitado en MySQL
    if ($db !== null) {
        try {
            // Obtener el nombre del producto antes de deshabilitar
            $getName = $db->prepare("SELECT `nombre` FROM `producto` WHERE LOWER(`codigo`) = LOWER(:codigo) OR `ID_stock` = :id LIMIT 1");
            $getName->execute([
                ':codigo' => (string)$codigo,
                ':id'     => is_numeric($codigo) ? (int)$codigo : 0
            ]);
            $foundRow = $getName->fetch();
            if ($foundRow && !empty($foundRow['nombre'])) {
                $productName = (string)$foundRow['nombre'];
            }

            $stmt = $db->prepare("UPDATE `producto` SET `fase` = 'deshabilitado' WHERE LOWER(`codigo`) = LOWER(:codigo) OR `ID_stock` = :id");
            $stmt->execute([
                ':codigo' => (string)$codigo,
                ':id'     => is_numeric($codigo) ? (int)$codigo : 0
            ]);
            if ($stmt->rowCount() > 0) {
                $updatedInDb = true;
            }
        } catch (Exception $e) {
            // Continuar con JSON si hay error en DB
        }
    }

    // 2. Marcar como deshabilitado en archivo JSON (el producto no se elimina, conserva su historial)
    $inventario = readJsonFile($file);
    foreach ($inventario as &$p) {
        $matchCode = strcasecmp((string)($p['codigo'] ?? ''), (string)$codigo) === 0;
        $matchId = isset($p['ID_stock']) && is_numeric($codigo) && (int)$p['ID_stock'] === (int)$codigo;
        if ($matchCode || $matchId) {
            $p['fase'] = 'deshabilitado';
            $productName = (string)($p['nombre'] ?? $productName);
            $updatedInJson = true;
        }
    }
    unset($p);

    if ($updatedInJson) {
        writeJsonFile($file, $inventario);
    }

    if ($updatedInDb || $updatedInJson) {
        $currentUser = getApiUser();
        $uName = $currentUser['usuario'] ?? 'Administrador';
        logActivity(
            $uName,
            'stock_deshabilitar',
            "Producto '{$productName}' (Cód: {$codigo}) marcado como deshabilitado por {$uName}",
            ['codigo' => $codigo, 'fase' => 'deshabilitado'],
            $currentUser['id'] ?? null
        );

        sendJson([
            'ok'            => true,
            'message'       => "El producto '{$productName}' fue deshabilitado correctamente y permanece en la base de datos con fase 'deshabilitado'.",
            'fase'          => 'deshabilitado',
            'codigo'        => $codigo,
            'nombre'        => $productName,
            'updatedInDb'   => $updatedInDb,
            'updatedInJson' => $updatedInJson
        ]);
    } else {
        sendJson(['error' => 'not_found', 'message' => 'Producto no encontrado en la base de datos ni en el inventario.'], 404);
    }
}

sendJson(['error' => 'method_not_allowed'], 405);
