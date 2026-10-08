<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

handleOptions();

$method = requestMethod();
$db = requireApiDatabase();
$superAdmin = isSuperAdminApi();

requireApiAuth();

// Validadores compartidos por creación/edición: restringen nombres, códigos y precios recibidos.
function isValidProductName(string $name): bool
{
    return preg_match('/\A[A-Za-z]+(?: [A-Za-z]+)*\z/', $name) === 1;
}

function isValidProductCode(string $code): bool
{
    return preg_match('/\A[0-9]+\z/', $code) === 1;
}

function isValidProductPrice(mixed $price): bool
{
    return is_scalar($price) && preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', (string)$price) === 1;
}

function isReservedCategoryName(string $name): bool
{
    $name = trim($name);
    return strcasecmp($name, 'General') === 0
        || preg_match('/\As[ií]n categor[ií]a\z/iu', $name) === 1;
}

// Busca o registra la subcategoría dentro de una categoría, devolviendo su clave persistente.
function findOrCreateSubcategory(PDO $db, ?int $categoryId, ?string $name): ?int
{
    $name = $name !== null ? trim($name) : '';
    if ($categoryId === null || $categoryId < 1 || $name === '') {
        return null;
    }

    $insert = $db->prepare("
        INSERT IGNORE INTO `sub_categoria` (`ID_categoria`, `nombre`)
        VALUES (:category_id, :name)
    ");
    $insert->execute([':category_id' => $categoryId, ':name' => $name]);
    $find = $db->prepare("
        SELECT `ID_sub_categoria`
        FROM `sub_categoria`
        WHERE `ID_categoria` = :category_id AND LOWER(`nombre`) = LOWER(:name)
        LIMIT 1
    ");
    $find->execute([':category_id' => $categoryId, ':name' => $name]);
    $id = $find->fetchColumn();
    return $id !== false ? (int)$id : null;
}

// Resuelve la categoría del producto por código o identificador para asociar su subcategoría.
function findProductCategoryId(PDO $db, string $code, int $productId): ?int
{
    $stmt = $db->prepare("
        SELECT `ID_categoria`
        FROM `producto`
        WHERE LOWER(`codigo`) = LOWER(:code) OR `ID_stock` = :id
        LIMIT 1
    ");
    $stmt->execute([':code' => $code, ':id' => $productId]);
    $categoryId = $stmt->fetchColumn();
    return $categoryId !== false && $categoryId !== null ? (int)$categoryId : null;
}

// GET: Obtener inventario desde la base de datos MySQL (tabla `producto` y `categoria`)
if ($method === 'GET') {
    // Las consultas auxiliares para categorías, precios y auditoría preceden al listado estándar.
    if (($_GET['action'] ?? '') === 'categories') {
        try {
            $stmt = $db->query("
                SELECT c.`ID_categoria`, c.`nombre`,
                       sc.`ID_sub_categoria`, sc.`nombre` AS `subcategoria`
                FROM `categoria` c
                LEFT JOIN `sub_categoria` sc ON sc.`ID_categoria` = c.`ID_categoria`
                WHERE LOWER(TRIM(c.`nombre`)) NOT IN ('general', 'sin categoría', 'sin categoria')
                ORDER BY c.`nombre` ASC, sc.`nombre` ASC
            ");
            $categories = [];
            foreach ($stmt->fetchAll() as $row) {
                $categoryId = (int)$row['ID_categoria'];
                if (!isset($categories[$categoryId])) {
                    $categories[$categoryId] = [
                        'ID_categoria' => $categoryId,
                        'nombre' => (string)$row['nombre'],
                        'subcategorias' => []
                    ];
                }
                if ($row['ID_sub_categoria'] !== null) {
                    $categories[$categoryId]['subcategorias'][] = [
                        'ID_sub_categoria' => (int)$row['ID_sub_categoria'],
                        'nombre' => (string)$row['subcategoria']
                    ];
                }
            }
            sendJson(['ok' => true, 'categorias' => array_values($categories)]);
        } catch (Exception $e) {
            error_log('Error al consultar categorías: ' . $e->getMessage());
            sendJson(['ok' => false, 'message' => 'No se pudieron cargar las categorías.'], 500);
        }
    }

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

        // El listado normal oculta productos deshabilitados salvo para Super Admin y devuelve datos tipados.
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
            error_log('Error al consultar auditoría de stock: ' . $e->getMessage());
            sendJson(['ok' => false, 'message' => 'No se pudo cargar la auditoría.'], 500);
        }
    }

    try {
        $invalidCategoryCount = (int)$db->query("
            SELECT COUNT(*)
            FROM `producto` p
            LEFT JOIN `categoria` c ON c.`ID_categoria` = p.`ID_categoria`
            WHERE c.`ID_categoria` IS NULL
               OR LOWER(TRIM(c.`nombre`)) IN ('general', 'sin categoría', 'sin categoria')
        ")->fetchColumn();
        if ($invalidCategoryCount > 0) {
            sendJson([
                'ok' => false,
                'error' => 'product_category_required',
                'message' => 'Hay productos sin una categoría válida. Reasignalos antes de continuar.'
            ], 409);
        }
        $stmt = $db->query("
            SELECT 
                p.ID_stock,
                p.nombre,
                COALESCE(p.codigo, CONCAT('PROD-', p.ID_stock)) AS codigo,
                COALESCE(p.cantTotal, 0) AS cantidad,
                COALESCE(p.cantVendida, 0) AS cantVendida,
                CAST(COALESCE(p.precio, 0) AS DECIMAL(10,2)) AS precio,
                ROUND(COALESCE(p.cantTotal, 0) * COALESCE(p.precio, 0), 2) AS total,
                c.nombre AS categoria,
                p.ID_categoria,
                " . ($superAdmin ? "COALESCE(p.fase, 'habilitado') AS fase," : "") . "
                p.marca,
                p.sub_nombre,
                p.ID_sub_categoria,
                COALESCE(sc.nombre, p.subcategoria) AS subcategoria
            FROM `producto` p
            INNER JOIN `categoria` c ON p.ID_categoria = c.ID_categoria
            LEFT JOIN `sub_categoria` sc ON p.ID_sub_categoria = sc.ID_sub_categoria
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
                'ID_sub_categoria' => $p['ID_sub_categoria'] !== null ? (int)$p['ID_sub_categoria'] : null,
                'subcategoria' => $p['subcategoria'] !== null ? (string)$p['subcategoria'] : null,
                'marca' => $p['marca'] !== null ? (string)$p['marca'] : null,
                'sub_nombre' => $p['sub_nombre'] !== null ? (string)$p['sub_nombre'] : null
            ];

            if ($superAdmin) {
                $fase = strtolower(trim((string)($p['fase'] ?? 'habilitado')));
                $producto['fase'] = $fase !== '' ? $fase : 'habilitado';
            }

            return $producto;
        }, $productos);

        sendJson($normalized);
    } catch (Exception $e) {
        error_log('Error al consultar inventario: ' . $e->getMessage());
        sendJson(['ok' => false, 'message' => 'No se pudo cargar el inventario.'], 500);
    }
}

// POST: Agregar un nuevo producto al inventario en MySQL (o rehabilitar si se especifica action=rehabilitar)
if ($method === 'POST') {
    // El POST enruta subacciones de creación de categoría, ajuste masivo de precios o alta de producto.
    $body = getJsonBody();
    $action = $_GET['action'] ?? ($body['action'] ?? 'create');

    if ($action === 'create_category') {
        requireAdminApi();
        $name = trim((string)($body['nombre'] ?? ''));
        if ($name === '') {
            sendJson(['ok' => false, 'message' => 'El nombre de la categoría es obligatorio.'], 400);
        }
        if (isReservedCategoryName($name)) {
            sendJson(['ok' => false, 'error' => 'reserved_category', 'message' => 'Ese nombre está reservado y no está disponible como categoría.'], 400);
        }
        try {
            $find = $db->prepare("SELECT `ID_categoria`, `nombre` FROM `categoria` WHERE LOWER(TRIM(`nombre`)) = LOWER(:nombre) LIMIT 1");
            $find->execute([':nombre' => $name]);
            if ($existing = $find->fetch()) {
                sendJson(['ok' => false, 'error' => 'category_exists', 'message' => 'Ya existe una categoría con ese nombre.'], 409);
            }
            $insert = $db->prepare("INSERT INTO `categoria` (`nombre`) VALUES (:nombre)");
            $insert->execute([':nombre' => $name]);
            sendJson([
                'ok' => true,
                'categoria' => ['ID_categoria' => (int)$db->lastInsertId(), 'nombre' => $name]
            ], 201);
        } catch (Exception $e) {
            error_log('Error al crear categoría: ' . $e->getMessage());
            sendJson(['ok' => false, 'message' => 'No se pudo crear la categoría.'], 500);
        }
    }

    if ($action === 'adjust_prices') {
        // Define productos objetivo, calcula precios y registra el lote en el historial de ajustes.
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
                       c.`nombre` AS categoria
                FROM `producto` p
                INNER JOIN `categoria` c ON c.`ID_categoria` = p.`ID_categoria`
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.`ID_stock`
                FOR UPDATE
            ";
            $selectStmt = $db->prepare($selectSql);
            $selectStmt->execute($params);
            $products = $selectStmt->fetchAll();
            if ($scope === 'selected' && count($products) !== count($products)) {
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
        if (!isValidProductCode($codigo)) {
            sendJson(['ok' => false, 'error' => 'invalid_code', 'message' => 'El código solo puede contener números del 0 al 9.'], 400);
        }

        $updStmt = $db->prepare("UPDATE `producto` SET `fase` = 'habilitado' WHERE LOWER(`codigo`) = LOWER(:codigo) OR `ID_stock` = :id");
        $updStmt->execute([
            ':codigo' => $codigo,
            ':id'     => is_numeric($codigo) ? (int)$codigo : 0
        ]);

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
    $precioRaw = $body['precio'] ?? 0;
    if (!isValidProductName($nombre)) {
        sendJson(['ok' => false, 'error' => 'invalid_name', 'message' => 'El nombre solo puede contener letras de la A a la Z y espacios entre palabras.'], 400);
    }
    if (!isValidProductCode($codigo)) {
        sendJson(['ok' => false, 'error' => 'invalid_code', 'message' => 'El código solo puede contener números del 0 al 9.'], 400);
    }
    if (!isValidProductPrice($precioRaw)) {
        sendJson(['ok' => false, 'error' => 'invalid_price', 'message' => 'El precio debe contener números y, opcionalmente, un punto decimal con hasta dos decimales.'], 400);
    }
    $precio = (float)$precioRaw;
    $cantidad = (int)($body['cantidad'] ?? ($body['stock'] ?? 0));
    $categoriaName = trim((string)($body['categoria'] ?? ''));
    $fase = 'habilitado';
    if ($categoriaName === '') {
        sendJson(['ok' => false, 'error' => 'category_required', 'message' => 'Seleccioná una categoría para el producto.'], 400);
    }
    if (isReservedCategoryName($categoriaName)) {
        sendJson(['ok' => false, 'error' => 'reserved_category', 'message' => 'Ese nombre está reservado y no está disponible como categoría.'], 400);
    }

    try {
        // Verificar si el código ya existe
        $checkStmt = $db->prepare("SELECT `ID_stock`, `ID_categoria`, `fase` FROM `producto` WHERE LOWER(`codigo`) = LOWER(:codigo) LIMIT 1");
        $checkStmt->execute([':codigo' => $codigo]);
        $existing = $checkStmt->fetch();
        if ($existing) {
            // Si existía pero estaba deshabilitado, actualizar y rehabilitar
            if (strtolower((string)($existing['fase'] ?? '')) === 'deshabilitado') {
                if (!isAdminApi()) {
                    sendJson(['ok' => false, 'error' => 'forbidden', 'message' => 'Solo un administrador puede rehabilitar productos deshabilitados.'], 403);
                }
                $subcategoryName = !empty($body['subcategoria']) ? trim((string)$body['subcategoria']) : null;
                $subcategoryId = findOrCreateSubcategory(
                    $db,
                    $existing['ID_categoria'] !== null ? (int)$existing['ID_categoria'] : null,
                    $subcategoryName
                );
                $updRehab = $db->prepare("
                    UPDATE `producto`
                    SET `nombre` = :nom, `marca` = :marca, `sub_nombre` = :sub_nombre,
                        `cantTotal` = :cant, `precio` = :prec, `subcategoria` = :subcategoria,
                        `ID_sub_categoria` = :subcategory_id, `fase` = 'habilitado'
                    WHERE `ID_stock` = :id
                ");
                $updRehab->execute([
                    ':nom'  => $nombre,
                    ':marca' => trim((string)($body['marca'] ?? '')) ?: null,
                    ':sub_nombre' => trim((string)($body['sub_nombre'] ?? '')) ?: null,
                    ':cant' => $cantidad,
                    ':prec' => $precio,
                    ':subcategoria' => $subcategoryName,
                    ':subcategory_id' => $subcategoryId,
                    ':id'   => (int)$existing['ID_stock']
                ]);

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

        $subcategoryName = !empty($body['subcategoria']) ? trim((string)$body['subcategoria']) : null;
        $subcategoryId = findOrCreateSubcategory($db, $catId, $subcategoryName);
        $brand = trim((string)($body['marca'] ?? '')) ?: null;
        $secondaryName = trim((string)($body['sub_nombre'] ?? '')) ?: null;

        // Insertar en tabla producto con fase habilitado
        $insertProd = $db->prepare("
            INSERT INTO `producto`
                (`nombre`, `marca`, `sub_nombre`, `codigo`, `cantTotal`, `cantVendida`, `ID_categoria`, `subcategoria`, `ID_sub_categoria`, `precio`, `fase`)
            VALUES
                (:nombre, :marca, :sub_nombre, :codigo, :cantTotal, 0, :ID_categoria, :subcategoria, :subcategory_id, :precio, 'habilitado')
        ");
        $insertProd->execute([
            ':nombre'       => $nombre,
            ':marca' => $brand,
            ':sub_nombre' => $secondaryName,
            ':codigo'       => $codigo,
            ':cantTotal'    => $cantidad,
            ':ID_categoria' => $catId,
            ':subcategoria' => $subcategoryName,
            ':subcategory_id' => $subcategoryId,
            ':precio'       => $precio
        ]);

        $newId = (int)$db->lastInsertId();

        $nuevoItem = [
            'id'           => $newId,
            'ID_stock'     => $newId,
            'categoria'    => $categoriaName,
            'ID_categoria' => $catId,
            'nombre'       => $nombre,
            'marca' => $brand,
            'sub_nombre' => $secondaryName,
            'codigo'       => $codigo,
            'precio'       => $precio,
            'cantidad'     => $cantidad,
            'cantVendida'  => 0,
            'total'        => $precio * $cantidad,
            'fase'         => 'habilitado',
            'subcategoria' => $subcategoryName,
            'ID_sub_categoria' => $subcategoryId
        ];

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
    // Admite una lista de existencias o un producto individual, siempre bajo autorización admin.
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
                SET `cantTotal` = :cant, `precio` = :precio, `nombre` = :nombre,
                    `marca` = COALESCE(:marca, `marca`),
                    `sub_nombre` = COALESCE(:sub_nombre, `sub_nombre`),
                    `subcategoria` = :subcategoria,
                    `ID_sub_categoria` = :subcategory_id,
                    `fase` = COALESCE(:fase, `fase`, 'habilitado')
                WHERE LOWER(`codigo`) = LOWER(:codigo) OR `ID_stock` = :id
            ");

            foreach ($payload as $item) {
                if (!is_array($item) || empty($item['codigo'])) {
                    sendJson(['ok' => false, 'error' => 'invalid_payload', 'message' => 'Cada producto debe incluir un código válido.'], 400);
                }
                $codigo = trim((string)$item['codigo']);
                $nombre = trim((string)($item['nombre'] ?? ''));
                $precioRaw = $item['precio'] ?? 0;
                if (!isValidProductName($nombre)) {
                    sendJson(['ok' => false, 'error' => 'invalid_name', 'message' => 'El nombre solo puede contener letras de la A a la Z y espacios entre palabras.'], 400);
                }
                if (!isValidProductPrice($precioRaw)) {
                    sendJson(['ok' => false, 'error' => 'invalid_price', 'message' => 'El precio debe contener números y, opcionalmente, un punto decimal con hasta dos decimales.'], 400);
                }
                $precio = (float)$precioRaw;
                $cantidad = (int)($item['cantidad'] ?? ($item['stock'] ?? 0));
                $subcategoria = !empty($item['subcategoria']) ? trim((string)$item['subcategoria']) : null;
                $id = (int)($item['ID_stock'] ?? ($item['id'] ?? 0));
                $faseItem = isset($item['fase']) ? strtolower(trim((string)$item['fase'])) : null;
                $categoryId = findProductCategoryId($db, $codigo, $id);
                $subcategoryId = findOrCreateSubcategory($db, $categoryId, $subcategoria);

                $updateStmt->execute([
                    ':cant'   => $cantidad,
                    ':precio' => $precio,
                    ':nombre' => $nombre,
                    ':marca' => isset($item['marca']) ? (trim((string)$item['marca']) ?: null) : null,
                    ':sub_nombre' => isset($item['sub_nombre']) ? (trim((string)$item['sub_nombre']) ?: null) : null,
                    ':subcategoria' => $subcategoria,
                    ':subcategory_id' => $subcategoryId,
                    ':codigo' => $codigo,
                    ':id'     => $id,
                    ':fase'   => $faseItem
                ]);
            }

            sendJson(['ok' => true, 'count' => count($payload)]);
        }

        // Si es un solo producto
        $codigo = trim((string)($payload['codigo'] ?? ''));
        $id = (int)($payload['ID_stock'] ?? ($payload['id'] ?? 0));
        $precioRaw = $payload['precio'] ?? null;
        $cantidad = (int)($payload['cantidad'] ?? ($payload['stock'] ?? 0));
        $nombre = trim((string)($payload['nombre'] ?? ''));
        $subcategoria = array_key_exists('subcategoria', $payload)
            ? (trim((string)$payload['subcategoria']) !== '' ? trim((string)$payload['subcategoria']) : null)
            : null;
        $fase = isset($payload['fase']) ? strtolower(trim((string)$payload['fase'])) : null;
        $brand = array_key_exists('marca', $payload) ? (trim((string)$payload['marca']) ?: null) : null;
        $secondaryName = array_key_exists('sub_nombre', $payload) ? (trim((string)$payload['sub_nombre']) ?: null) : null;

        if ($nombre !== '' && !isValidProductName($nombre)) {
            sendJson(['ok' => false, 'error' => 'invalid_name', 'message' => 'El nombre solo puede contener letras de la A a la Z y espacios entre palabras.'], 400);
        }
        if (array_key_exists('precio', $payload) && !isValidProductPrice($precioRaw)) {
            sendJson(['ok' => false, 'error' => 'invalid_price', 'message' => 'El precio debe contener números y, opcionalmente, un punto decimal con hasta dos decimales.'], 400);
        }
        $precio = $precioRaw !== null ? (float)$precioRaw : 0.0;

        $updates = [];
        $params = [':codigo' => $codigo, ':id' => $id];

        if ($nombre !== '') {
            $updates[] = "`nombre` = :nombre";
            $params[':nombre'] = $nombre;
        }
        if (array_key_exists('subcategoria', $payload)) {
            $updates[] = "`subcategoria` = :subcategoria";
            $params[':subcategoria'] = $subcategoria;
            $categoryId = findProductCategoryId($db, $codigo, $id);
            $updates[] = "`ID_sub_categoria` = :subcategory_id";
            $params[':subcategory_id'] = findOrCreateSubcategory($db, $categoryId, $subcategoria);
        }
        if (array_key_exists('marca', $payload)) {
            $updates[] = "`marca` = :marca";
            $params[':marca'] = $brand;
        }
        if (array_key_exists('sub_nombre', $payload)) {
            $updates[] = "`sub_nombre` = :sub_nombre";
            $params[':sub_nombre'] = $secondaryName;
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
    // Realiza baja lógica de productos; la eliminación de categoría reasigna primero sus productos.
    requireAdminApi();
    if (($_GET['action'] ?? '') === 'delete_category') {
        $body = getJsonBody();
        $categoryId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if ($categoryId === false || $categoryId < 1) {
            sendJson(['ok' => false, 'message' => 'Se requiere un identificador de categoría válido.'], 400);
        }
        $destinationId = filter_var($body['destination_id'] ?? null, FILTER_VALIDATE_INT);
        if ($destinationId === false || $destinationId < 1 || $destinationId === $categoryId) {
            sendJson(['ok' => false, 'error' => 'invalid_destination', 'message' => 'Seleccioná una categoría destino válida y distinta.'], 400);
        }

        try {
            $db->beginTransaction();
            $find = $db->prepare("SELECT `ID_categoria`, `nombre` FROM `categoria` WHERE `ID_categoria` IN (:source, :destination) FOR UPDATE");
            $find->execute([':source' => $categoryId, ':destination' => $destinationId]);
            $lockedCategories = $find->fetchAll(PDO::FETCH_KEY_PAIR);
            $categoryName = $lockedCategories[$categoryId] ?? false;
            if ($categoryName === false) {
                $db->rollBack();
                sendJson(['ok' => false, 'error' => 'not_found', 'message' => 'No se encontró la categoría.'], 404);
            }
            $destinationName = $lockedCategories[$destinationId] ?? false;
            if ($destinationName === false || isReservedCategoryName((string)$destinationName)) {
                $db->rollBack();
                sendJson(['ok' => false, 'error' => 'invalid_destination', 'message' => 'La categoría destino no está disponible.'], 400);
            }

            $copySubcategories = $db->prepare("
                INSERT INTO `sub_categoria` (`ID_categoria`, `nombre`)
                SELECT DISTINCT :destination, TRIM(COALESCE(sc.`nombre`, p.`subcategoria`))
                FROM `producto` p
                LEFT JOIN `sub_categoria` sc ON sc.`ID_sub_categoria` = p.`ID_sub_categoria`
                WHERE p.`ID_categoria` = :source
                  AND COALESCE(sc.`nombre`, p.`subcategoria`) IS NOT NULL
                  AND TRIM(COALESCE(sc.`nombre`, p.`subcategoria`)) <> ''
                ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`)
            ");
            $copySubcategories->execute([':destination' => $destinationId, ':source' => $categoryId]);
            $moveProducts = $db->prepare("
                UPDATE `producto` p
                LEFT JOIN `sub_categoria` source_sub ON source_sub.`ID_sub_categoria` = p.`ID_sub_categoria`
                LEFT JOIN `sub_categoria` destination_sub
                    ON destination_sub.`ID_categoria` = :destination_join
                   AND LOWER(destination_sub.`nombre`) = LOWER(TRIM(COALESCE(source_sub.`nombre`, p.`subcategoria`)))
                SET p.`ID_categoria` = :destination_set,
                    p.`ID_sub_categoria` = destination_sub.`ID_sub_categoria`
                WHERE p.`ID_categoria` = :source
            ");
            $moveProducts->execute([
                ':destination_join' => $destinationId,
                ':destination_set' => $destinationId,
                ':source' => $categoryId
            ]);
            $affectedProducts = $moveProducts->rowCount();
            $delete = $db->prepare("DELETE FROM `categoria` WHERE `ID_categoria` = :id");
            $delete->execute([':id' => $categoryId]);
            $db->commit();

            $user = getApiUser();
            logActivity(
                (string)($user['usuario'] ?? 'Administrador'),
                'categoria_eliminada',
                "Categoría '{$categoryName}' eliminada; {$affectedProducts} productos fueron reasignados a '{$destinationName}'.",
                ['id_categoria' => $categoryId, 'id_categoria_destino' => $destinationId, 'productos_reasignados' => $affectedProducts],
                isset($user['id']) ? (int)$user['id'] : null
            );
            sendJson(['ok' => true, 'message' => "Categoría '{$categoryName}' eliminada y sus productos reasignados a '{$destinationName}'."]);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Error al eliminar categoría: ' . $e->getMessage());
            sendJson(['ok' => false, 'message' => 'No se pudo eliminar la categoría.'], 500);
        }
    }

    $codigo = $_GET['codigo'] ?? null;
    if (!$codigo) {
        $body = getJsonBody();
        $codigo = $body['codigo'] ?? null;
    }

    if (!$codigo) {
        sendJson(['error' => 'missing_code', 'message' => 'Se requiere el parámetro codigo.'], 400);
    }

    $productName = (string)$codigo;

    $getName = $db->prepare("SELECT `nombre` FROM `producto` WHERE LOWER(`codigo`) = LOWER(:codigo) OR `ID_stock` = :id LIMIT 1");
    $getName->execute([
        ':codigo' => (string)$codigo,
        ':id'     => is_numeric($codigo) ? (int)$codigo : 0
    ]);
    $foundRow = $getName->fetch();
    if (!$foundRow) {
        sendJson(['error' => 'not_found', 'message' => 'Producto no encontrado en la base de datos.'], 404);
    }
    $productName = (string)$foundRow['nombre'];

    $stmt = $db->prepare("UPDATE `producto` SET `fase` = 'deshabilitado' WHERE LOWER(`codigo`) = LOWER(:codigo) OR `ID_stock` = :id");
    $stmt->execute([
        ':codigo' => (string)$codigo,
        ':id'     => is_numeric($codigo) ? (int)$codigo : 0
    ]);
    if ($stmt->rowCount() > 0) {
        $currentUser = getApiUser();
        $uName = $currentUser['usuario'] ?? 'Administrador';
        logActivity(
            $uName,
            'stock_deshabilitar',
            "Producto '{$productName}' (Cód: {$codigo}) marcado como deshabilitado por {$uName}",
            ['codigo' => $codigo, 'fase' => 'deshabilitado'],
            $currentUser['id'] ?? null
        );
    }
    sendJson([
        'ok' => true,
        'message' => "El producto '{$productName}' fue deshabilitado correctamente y permanece en la base de datos con fase 'deshabilitado'.",
        'fase' => 'deshabilitado',
        'codigo' => $codigo,
        'nombre' => $productName
    ]);
}

sendJson(['error' => 'method_not_allowed'], 405);
