<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

handleOptions();

$file = dataPath('inventario.json');
$method = requestMethod();
$db = getDBConnection();

// GET: Obtener inventario desde la base de datos MySQL (tabla `producto` y `categoria`)
if ($method === 'GET') {
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
                NULL AS subcategoria
            FROM `producto` p
            LEFT JOIN `categoria` c ON p.ID_categoria = c.ID_categoria
            ORDER BY p.ID_stock ASC
        ");
        $productos = $stmt->fetchAll();

        // Convertir tipos numéricos para consistencia en JSON
        $normalized = array_map(function ($p) {
            return [
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
                'subcategoria' => null
            ];
        }, $productos);

        // Guardar copia de seguridad en JSON
        writeJsonFile($file, $normalized);

        sendJson($normalized);
    } catch (Exception $e) {
        // Fallback a JSON si hubiese algún problema temporal
        $inventario = readJsonFile($file);
        sendJson($inventario);
    }
}

// POST: Agregar un nuevo producto al inventario en MySQL
if ($method === 'POST') {
    $body = getJsonBody();
    if (!is_array($body) || empty($body['nombre']) || empty($body['codigo'])) {
        sendJson(['error' => 'invalid_payload', 'message' => 'Faltan campos obligatorios (nombre, codigo).'], 400);
    }

    $nombre = trim((string)$body['nombre']);
    $codigo = trim((string)$body['codigo']);
    $precio = (float)($body['precio'] ?? 0);
    $cantidad = (int)($body['cantidad'] ?? ($body['stock'] ?? 0));
    $categoriaName = trim((string)($body['categoria'] ?? 'General'));
    if ($categoriaName === '') {
        $categoriaName = 'General';
    }

    try {
        // Verificar si el código ya existe
        $checkStmt = $db->prepare("SELECT `ID_stock` FROM `producto` WHERE LOWER(`codigo`) = LOWER(:codigo) LIMIT 1");
        $checkStmt->execute([':codigo' => $codigo]);
        if ($checkStmt->fetch()) {
            sendJson(['error' => 'item_exists', 'message' => 'Ya existe un producto con este código.'], 409);
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

        // Insertar en tabla producto
        $insertProd = $db->prepare("
            INSERT INTO `producto` (`nombre`, `codigo`, `cantTotal`, `cantVendida`, `ID_categoria`, `precio`)
            VALUES (:nombre, :codigo, :cantTotal, 0, :ID_categoria, :precio)
        ");
        $insertProd->execute([
            ':nombre'       => $nombre,
            ':codigo'       => $codigo,
            ':cantTotal'    => $cantidad,
            ':ID_categoria' => $catId,
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
            "Nuevo producto '{$nombre}' (Cód: {$codigo}) agregado con stock {$cantidad} y precio \${$precio}",
            $nuevoItem,
            $currentUser['id'] ?? null
        );

        sendJson(['ok' => true, 'item' => $nuevoItem]);
    } catch (Exception $e) {
        sendJson(['error' => 'save_error', 'message' => 'Error al guardar en base de datos: ' . $e->getMessage()], 500);
    }
}

// PUT: Actualizar inventario en MySQL
if ($method === 'PUT') {
    $payload = getJsonBody();

    if (!is_array($payload)) {
        sendJson(['error' => 'invalid_payload', 'message' => 'El cuerpo debe ser un array de productos o un objeto.'], 400);
    }

    try {
        // Si viene un array de productos
        if (isset($payload[0]) || empty($payload)) {
            $updateStmt = $db->prepare("
                UPDATE `producto` 
                SET `cantTotal` = :cant, `precio` = :precio, `nombre` = :nombre 
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
                $id = (int)($item['ID_stock'] ?? ($item['id'] ?? 0));

                $updateStmt->execute([
                    ':cant'   => $cantidad,
                    ':precio' => $precio,
                    ':nombre' => $nombre,
                    ':codigo' => $codigo,
                    ':id'     => $id
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

        $stmt = $db->prepare("
            UPDATE `producto` 
            SET `cantTotal` = :cant, `precio` = :precio, `nombre` = :nombre 
            WHERE LOWER(`codigo`) = LOWER(:codigo) OR `ID_stock` = :id
        ");
        $stmt->execute([
            ':cant'   => $cantidad,
            ':precio' => $precio,
            ':nombre' => $nombre,
            ':codigo' => $codigo,
            ':id'     => $id
        ]);

        $currentUser = getApiUser();
        $uName = $currentUser['usuario'] ?? 'Administrador';
        logActivity(
            $uName,
            'stock_modificar',
            "Stock modificado para producto '{$nombre}' (Cód: {$codigo}) a cantidad {$cantidad}, precio \${$precio}",
            ['codigo' => $codigo, 'cantidad' => $cantidad, 'precio' => $precio],
            $currentUser['id'] ?? null
        );

        sendJson(['ok' => true, 'message' => 'Producto actualizado en base de datos.']);
    } catch (Exception $e) {
        sendJson(['error' => 'save_error', 'message' => 'Error al actualizar base de datos: ' . $e->getMessage()], 500);
    }
}

// DELETE: Eliminar un producto por código o ID_stock
if ($method === 'DELETE') {
    $codigo = $_GET['codigo'] ?? null;
    if (!$codigo) {
        $body = getJsonBody();
        $codigo = $body['codigo'] ?? null;
    }

    if (!$codigo) {
        sendJson(['error' => 'missing_code', 'message' => 'Se requiere el parámetro codigo.'], 400);
    }

    $deletedInDb = false;
    $deletedInJson = false;

    // 1. Intentar eliminar en MySQL
    if ($db !== null) {
        try {
            $stmt = $db->prepare("DELETE FROM `producto` WHERE LOWER(`codigo`) = LOWER(:codigo) OR `ID_stock` = :id");
            $stmt->execute([
                ':codigo' => (string)$codigo,
                ':id'     => is_numeric($codigo) ? (int)$codigo : 0
            ]);
            if ($stmt->rowCount() > 0) {
                $deletedInDb = true;
            }
        } catch (Exception $e) {
            // Continuar con JSON si hay error en DB
        }
    }

    // 2. Eliminar y sincronizar en archivo JSON
    $inventario = readJsonFile($file);
    $initialCount = count($inventario);
    $inventario = array_values(array_filter($inventario, function ($p) use ($codigo) {
        $matchCode = strcasecmp((string)($p['codigo'] ?? ''), (string)$codigo) === 0;
        $matchId = isset($p['ID_stock']) && is_numeric($codigo) && (int)$p['ID_stock'] === (int)$codigo;
        return !$matchCode && !$matchId;
    }));

    if (count($inventario) < $initialCount) {
        $deletedInJson = true;
        writeJsonFile($file, $inventario);
    }

    if ($deletedInDb || $deletedInJson) {
        $currentUser = getApiUser();
        $uName = $currentUser['usuario'] ?? 'Administrador';
        logActivity(
            $uName,
            'stock_eliminar',
            "Producto con código/ID '{$codigo}' eliminado del inventario por {$uName}",
            ['codigo' => $codigo],
            $currentUser['id'] ?? null
        );

        sendJson([
            'ok' => true,
            'message' => 'Producto eliminado correctamente de la base de datos y del inventario.',
            'deletedInDb' => $deletedInDb,
            'deletedInJson' => $deletedInJson
        ]);
    } else {
        sendJson(['error' => 'not_found', 'message' => 'Producto no encontrado en la base de datos ni en el inventario.'], 404);
    }
}

sendJson(['error' => 'method_not_allowed'], 405);
