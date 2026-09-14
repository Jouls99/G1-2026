<?php
declare(strict_types=1);

/**
 * Gestor de Conexión a la Base de Datos MySQL
 * Proyecto: SOS Cosméticos
 */

const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'sos_cosmeticos';
const DB_USER = 'root';
const DB_PASS = '';

/**
 * Obtiene o crea la conexión PDO con MySQL.
 * Si la base de datos no existe, la inicializa automáticamente con su esquema.
 */
function getDBConnection(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // Si la base no existe (código 1049), intentar crearla e inicializarla
        if ($e->getCode() === 1049 || str_contains($e->getMessage(), 'Unknown database')) {
            initDatabase();
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } else {
            // Intentar inicializar la base de datos
            try {
                initDatabase();
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $ex) {
                throw new PDOException("Error de conexión a la base de datos MySQL: " . $ex->getMessage(), (int)$ex->getCode());
            }
        }
    }

    return $pdo;
}

/**
 * Crea la base de datos y sus tablas si no existen, e importa datos iniciales si está vacía.
 */
function initDatabase(): void
{
    $serverDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT);
    $pdoServer = new PDO($serverDsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Crear base de datos si no existe
    $pdoServer->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish2_ci");
    $pdoServer->exec("USE `" . DB_NAME . "`");

    // Crear tablas según esquema sos_cosmeticos.sql
    $pdoServer->exec("
        CREATE TABLE IF NOT EXISTS `categoria` (
            `ID_categoria` int(11) NOT NULL AUTO_INCREMENT,
            `nombre` varchar(100) NOT NULL,
            PRIMARY KEY (`ID_categoria`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
    ");

    $pdoServer->exec("
        CREATE TABLE IF NOT EXISTS `producto` (
            `ID_stock` int(11) NOT NULL AUTO_INCREMENT,
            `nombre` varchar(100) NOT NULL,
            `codigo` varchar(100) DEFAULT NULL,
            `cantTotal` int(11) DEFAULT 0,
            `cantVendida` int(11) DEFAULT 0,
            `ID_categoria` int(11) DEFAULT NULL,
            `precio` decimal(10,2) DEFAULT 0.00,
            PRIMARY KEY (`ID_stock`),
            KEY `fk_producto_categoria` (`ID_categoria`),
            CONSTRAINT `fk_producto_categoria` FOREIGN KEY (`ID_categoria`) REFERENCES `categoria` (`ID_categoria`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
    ");

    $pdoServer->exec("
        CREATE TABLE IF NOT EXISTS `facturacion` (
            `ID_factura` int(11) NOT NULL AUTO_INCREMENT,
            `fecha` date NOT NULL,
            `cantidadVendida` int(11) NOT NULL,
            `precioFinal` decimal(10,2) NOT NULL,
            `ganancia` decimal(10,2) GENERATED ALWAYS AS (`cantidadVendida` * `precioFinal`) STORED,
            `ID_stock` int(11) DEFAULT NULL,
            PRIMARY KEY (`ID_factura`),
            KEY `fk_facturacion_producto` (`ID_stock`),
            CONSTRAINT `fk_facturacion_producto` FOREIGN KEY (`ID_stock`) REFERENCES `producto` (`ID_stock`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
    ");

    $pdoServer->exec("
        CREATE TABLE IF NOT EXISTS `usuario` (
            `id_usuario` int(11) NOT NULL AUTO_INCREMENT,
            `nombre` varchar(100) DEFAULT NULL,
            `contraseña` varchar(100) DEFAULT NULL,
            `rol` varchar(50) DEFAULT 'vendedor',
            `ultimo_acceso` datetime DEFAULT NULL,
            `fecha_creacion` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_usuario`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
    ");

    $pdoServer->exec("
        CREATE TABLE IF NOT EXISTS `actividad_usuario` (
            `id_actividad` int(11) NOT NULL AUTO_INCREMENT,
            `id_usuario` int(11) DEFAULT NULL,
            `usuario` varchar(100) NOT NULL,
            `tipo_accion` varchar(50) NOT NULL,
            `descripcion` text NOT NULL,
            `detalles` text DEFAULT NULL,
            `fecha` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_actividad`),
            KEY `fk_actividad_usuario` (`id_usuario`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
    ");

    // Verificar si la tabla producto tiene la columna codigo (si fue creada antes sin ella)
    try {
        $cols = $pdoServer->query("SHOW COLUMNS FROM `producto` LIKE 'codigo'")->fetchAll();
        if (empty($cols)) {
            $pdoServer->exec("ALTER TABLE `producto` ADD COLUMN `codigo` varchar(100) DEFAULT NULL AFTER `nombre`");
        }
    } catch (Exception $e) {
        // Ignorar si ya existe
    }

    // Verificar si la tabla facturacion tiene la columna usuario
    try {
        $factCols = $pdoServer->query("SHOW COLUMNS FROM `facturacion` LIKE 'usuario'")->fetchAll();
        if (empty($factCols)) {
            $pdoServer->exec("ALTER TABLE `facturacion` ADD COLUMN `usuario` varchar(100) DEFAULT 'gomez11' AFTER `ID_stock`");
        }
    } catch (Exception $e) {
        // Ignorar
    }

    // Verificar si la tabla usuario tiene las columnas ultimo_acceso y fecha_creacion
    try {
        $userCols = $pdoServer->query("SHOW COLUMNS FROM `usuario` LIKE 'ultimo_acceso'")->fetchAll();
        if (empty($userCols)) {
            $pdoServer->exec("ALTER TABLE `usuario` ADD COLUMN `ultimo_acceso` datetime DEFAULT NULL AFTER `rol`");
        }
        $userDateCols = $pdoServer->query("SHOW COLUMNS FROM `usuario` LIKE 'fecha_creacion'")->fetchAll();
        if (empty($userDateCols)) {
            $pdoServer->exec("ALTER TABLE `usuario` ADD COLUMN `fecha_creacion` datetime DEFAULT CURRENT_TIMESTAMP AFTER `ultimo_acceso`");
        }
    } catch (Exception $e) {
        // Ignorar
    }

    // Verificar si la tabla usuario tiene id_usuario como PRIMARY KEY AUTO_INCREMENT
    try {
        $userCols = $pdoServer->query("SHOW COLUMNS FROM `usuario` LIKE 'id_usuario'")->fetch();
        if ($userCols && ($userCols['Key'] !== 'PRI' || !str_contains((string)($userCols['Extra'] ?? ''), 'auto_increment'))) {
            $pdoServer->exec("ALTER TABLE `usuario` MODIFY `id_usuario` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY");
        }
    } catch (Exception $e) {
        // Ignorar
    }

    // Migrar o sembrar datos iniciales si no hay productos cargados
    $countProd = (int)$pdoServer->query("SELECT COUNT(*) FROM `producto`")->fetchColumn();
    if ($countProd === 0) {
        // Buscar archivo inventario.json para migrar datos existentes
        $jsonPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'inventario.json';
        if (file_exists($jsonPath)) {
            $jsonData = json_decode(file_get_contents($jsonPath) ?: '[]', true);
            if (is_array($jsonData)) {
                // Mapear categorías
                $catStmt = $pdoServer->prepare("INSERT INTO `categoria` (`nombre`) VALUES (:nombre)");
                $getCatStmt = $pdoServer->prepare("SELECT `ID_categoria` FROM `categoria` WHERE `nombre` = :nombre LIMIT 1");
                $prodStmt = $pdoServer->prepare("
                    INSERT INTO `producto` (`nombre`, `codigo`, `cantTotal`, `cantVendida`, `ID_categoria`, `precio`)
                    VALUES (:nombre, :codigo, :cantTotal, :cantVendida, :ID_categoria, :precio)
                ");

                $catMap = [];
                foreach ($jsonData as $item) {
                    $catName = trim((string)($item['categoria'] ?? 'General'));
                    if ($catName === '') $catName = 'General';

                    if (!isset($catMap[$catName])) {
                        $getCatStmt->execute([':nombre' => $catName]);
                        $catId = $getCatStmt->fetchColumn();
                        if (!$catId) {
                            $catStmt->execute([':nombre' => $catName]);
                            $catId = (int)$pdoServer->lastInsertId();
                        }
                        $catMap[$catName] = (int)$catId;
                    }

                    $prodStmt->execute([
                        ':nombre'       => (string)($item['nombre'] ?? 'Producto'),
                        ':codigo'       => (string)($item['codigo'] ?? ('COD-' . uniqid())),
                        ':cantTotal'    => (int)($item['cantidad'] ?? 0),
                        ':cantVendida'  => 0,
                        ':ID_categoria' => $catMap[$catName],
                        ':precio'       => (float)($item['precio'] ?? 0)
                    ]);
                }
            }
        }
    }

    // Migrar ventas iniciales a tabla facturacion si está vacía
    $countFact = (int)$pdoServer->query("SELECT COUNT(*) FROM `facturacion`")->fetchColumn();
    if ($countFact === 0) {
        $ventasJsonPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ventas.json';
        if (file_exists($ventasJsonPath)) {
            $ventasJson = json_decode(file_get_contents($ventasJsonPath) ?: '[]', true);
            if (is_array($ventasJson)) {
                $findProdStmt = $pdoServer->prepare("SELECT `ID_stock` FROM `producto` WHERE LOWER(`codigo`) = LOWER(:codigo) OR LOWER(`nombre`) = LOWER(:nombre) LIMIT 1");
                $insFactStmt = $pdoServer->prepare("
                    INSERT INTO `facturacion` (`fecha`, `cantidadVendida`, `precioFinal`, `ID_stock`)
                    VALUES (:fecha, :cant, :precio, :id_stock)
                ");
                $updProdStmt = $pdoServer->prepare("
                    UPDATE `producto` SET `cantVendida` = COALESCE(`cantVendida`, 0) + :cant WHERE `ID_stock` = :id_stock
                ");

                foreach ($ventasJson as $v) {
                    if (empty($v['productos']) || !is_array($v['productos'])) continue;
                    $fechaSql = date('Y-m-d');
                    if (!empty($v['fecha'])) {
                        $ts = strtotime((string)$v['fecha']);
                        if ($ts !== false) $fechaSql = date('Y-m-d', $ts);
                    }

                    foreach ($v['productos'] as $p) {
                        $cod = trim((string)($p['codigo'] ?? ''));
                        $nom = trim((string)($p['nombre'] ?? ''));
                        $cant = (int)($p['cantidad'] ?? 1);
                        $precio = (float)($p['precio'] ?? 0);

                        $findProdStmt->execute([':codigo' => $cod, ':nombre' => $nom]);
                        $idStock = $findProdStmt->fetchColumn();

                        if ($idStock) {
                            $insFactStmt->execute([
                                ':fecha'    => $fechaSql,
                                ':cant'     => $cant,
                                ':precio'   => $precio,
                                ':id_stock' => (int)$idStock
                            ]);
                            $updProdStmt->execute([
                                ':cant'     => $cant,
                                ':id_stock' => (int)$idStock
                            ]);
                        }
                    }
                }
            }
        }
    }

    // Sembrar usuario inicial si está vacío
    $countUser = (int)$pdoServer->query("SELECT COUNT(*) FROM `usuario`")->fetchColumn();
    if ($countUser === 0) {
        $userJsonPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'users.json';
        if (file_exists($userJsonPath)) {
            $userJson = json_decode(file_get_contents($userJsonPath) ?: '[]', true);
            if (is_array($userJson) && !empty($userJson)) {
                $userStmt = $pdoServer->prepare("INSERT INTO `usuario` (`nombre`, `contraseña`, `rol`, `fecha_creacion`) VALUES (:nombre, :pass, :rol, NOW())");
                foreach ($userJson as $u) {
                    $userStmt->execute([
                        ':nombre' => (string)($u['usuario'] ?? 'admin'),
                        ':pass'   => (string)($u['password'] ?? '1234'),
                        ':rol'    => (string)($u['role'] ?? 'administrador')
                    ]);
                }
            }
        } else {
            $pdoServer->exec("INSERT INTO `usuario` (`nombre`, `contraseña`, `rol`, `fecha_creacion`) VALUES ('gomez11', 'santu99', 'administrador', NOW())");
            $pdoServer->exec("INSERT INTO `usuario` (`nombre`, `contraseña`, `rol`, `fecha_creacion`) VALUES ('vendedor_demo', '1234', 'vendedor', NOW())");
        }
    } else {
        // Asegurar que al menos un usuario tenga rol de administrador
        $adminCount = (int)$pdoServer->query("SELECT COUNT(*) FROM `usuario` WHERE `rol` IN ('administrador', 'admin')")->fetchColumn();
        if ($adminCount === 0) {
            $pdoServer->exec("UPDATE `usuario` SET `rol` = 'administrador' WHERE `nombre` = 'gomez11' OR `id_usuario` = 1 LIMIT 1");
        }
    }

    // Sembrar log inicial si está vacío
    $countAct = (int)$pdoServer->query("SELECT COUNT(*) FROM `actividad_usuario`")->fetchColumn();
    if ($countAct === 0) {
        $pdoServer->exec("
            INSERT INTO `actividad_usuario` (`usuario`, `tipo_accion`, `descripcion`, `detalles`, `fecha`)
            VALUES ('Sistema', 'sistema_inicio', 'Inicialización del sistema de gestión y control de usuarios', '{\"version\":\"2.0\"}', NOW())
        ");
    }
}

// Variable de conveniencia para scripts que requieran $conexion o $pdo directamente
try {
    $conexion = getDBConnection();
    $pdo = $conexion;
} catch (Exception $e) {
    // Si falla en tiempo de inclusión estático, se puede capturar en la llamada a getDBConnection()
    $conexion = null;
    $pdo = null;
}
