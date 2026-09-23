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
 * Si MySQL no responde, retorna null de forma segura permitiendo el respaldo JSON.
 */
function getDBConnection(): ?PDO
{
    static $pdo = null;
    static $failed = false;

    if ($pdo !== null) {
        return $pdo;
    }

    if ($failed) {
        return null;
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
        // Intentar crear la base de datos y esquema si no existía
        try {
            initDatabase();
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $ex) {
            $failed = true;
            error_log("Aviso de Conexión MySQL: " . $ex->getMessage());
            return null;
        }
    }

    ensureProductPhaseColumn($pdo);
    syncDefaultUsers($pdo);

    return $pdo;
}

/** Asegura que producto.fase exista y use los estados textuales del sistema. */
function ensureProductPhaseColumn(PDO $pdo): void
{
    $phaseColumn = $pdo->query("SHOW COLUMNS FROM `producto` LIKE 'fase'")->fetch();

    if (!$phaseColumn) {
        $pdo->exec("ALTER TABLE `producto` ADD COLUMN `fase` varchar(50) NOT NULL DEFAULT 'habilitado' AFTER `precio`");
        return;
    }

    if (stripos((string)($phaseColumn['Type'] ?? ''), 'int') === 0) {
        $pdo->exec("ALTER TABLE `producto` MODIFY COLUMN `fase` varchar(50) NOT NULL DEFAULT 'habilitado'");
        $pdo->exec("UPDATE `producto` SET `fase` = 'habilitado' WHERE `fase` = '1' OR `fase` = '0'");
    }

    $pdo->exec("UPDATE `producto` SET `fase` = 'habilitado' WHERE `fase` IS NULL OR `fase` = ''");
}

/**
 * Asegura que las cuentas iniciales existan y conserven sus roles correctos.
 */
function syncDefaultUsers(PDO $pdo): void
{
    try {
        migratePasswordColumn($pdo);

        $seedUsers = [
            ['nombre' => 'gomez11', 'password' => 'santu99', 'rol' => 'superadmin'],
            ['nombre' => 'GOMEZ ADMIN', 'password' => '1234', 'rol' => 'administrador'],
            ['nombre' => 'vendedor_demo', 'password' => '1234', 'rol' => 'vendedor']
        ];
        $seedStmt = $pdo->prepare("
            INSERT INTO `usuario` (`nombre`, `password`, `rol`, `fecha_creacion`)
            VALUES (:nombre, :password, :rol, NOW())
            ON DUPLICATE KEY UPDATE `rol` = VALUES(`rol`)
        ");
        foreach ($seedUsers as $seedUser) {
            $seedStmt->execute([
                ':nombre' => $seedUser['nombre'],
                ':password' => password_hash($seedUser['password'], PASSWORD_DEFAULT),
                ':rol' => $seedUser['rol']
            ]);
        }
    } catch (Exception $e) {
        // El respaldo JSON continúa disponible si MySQL no permite sincronizar.
    }
}

/** Migra la columna antigua y convierte contraseñas heredadas a hashes. */
function migratePasswordColumn(PDO $pdo): void
{
    $passwordColumn = $pdo->query("SHOW COLUMNS FROM `usuario` LIKE 'password'")->fetch();
    $oldColumn = $pdo->query("SHOW COLUMNS FROM `usuario` LIKE 'contraseña'")->fetch();

    if (!$passwordColumn && $oldColumn) {
        $pdo->exec("ALTER TABLE `usuario` CHANGE `contraseña` `password` varchar(255) NOT NULL");
    } elseif (!$passwordColumn) {
        $pdo->exec("ALTER TABLE `usuario` ADD COLUMN `password` varchar(255) NOT NULL AFTER `nombre`");
    }

    $users = $pdo->query("SELECT `id_usuario`, `password` FROM `usuario`")->fetchAll();
    $update = $pdo->prepare("UPDATE `usuario` SET `password` = :password WHERE `id_usuario` = :id");
    foreach ($users as $user) {
        $stored = (string)$user['password'];
        if (password_get_info($stored)['algoName'] === 'unknown') {
            $update->execute([
                ':password' => password_hash($stored, PASSWORD_DEFAULT),
                ':id' => (int)$user['id_usuario']
            ]);
        }
    }
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

    // 1. Crear base de datos si no existe
    $pdoServer->exec("
        CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`
        CHARACTER SET utf8mb4
        COLLATE utf8mb4_spanish2_ci;
    ");

    $pdoServer->exec("USE `" . DB_NAME . "`;");

    // 2. Tabla Categoria
    $pdoServer->exec("
        CREATE TABLE IF NOT EXISTS `categoria` (
            `ID_categoria` int(11) NOT NULL AUTO_INCREMENT,
            `nombre` varchar(100) NOT NULL,
            PRIMARY KEY (`ID_categoria`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
    ");

    // 3. Tabla Producto
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
            CONSTRAINT `fk_producto_categoria`
                FOREIGN KEY (`ID_categoria`) REFERENCES `categoria` (`ID_categoria`)
                ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
    ");

    // 4. Tabla Facturacion
    $pdoServer->exec("
        CREATE TABLE IF NOT EXISTS `facturacion` (
            `ID_factura` int(11) NOT NULL AUTO_INCREMENT,
            `fecha` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `cantidadVendida` int(11) NOT NULL,
            `precioFinal` decimal(10,2) NOT NULL,
            `ganancia` decimal(10,2) GENERATED ALWAYS AS (`cantidadVendida` * `precioFinal`) STORED,
            `ID_stock` int(11) DEFAULT NULL,
            `usuario` varchar(100) DEFAULT 'gomez11',
            PRIMARY KEY (`ID_factura`),
            KEY `fk_facturacion_producto` (`ID_stock`),
            CONSTRAINT `fk_facturacion_producto`
                FOREIGN KEY (`ID_stock`) REFERENCES `producto` (`ID_stock`)
                ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
    ");

    // 5. Tabla Usuario
    $pdoServer->exec("
        CREATE TABLE IF NOT EXISTS `usuario` (
            `id_usuario` int(11) NOT NULL AUTO_INCREMENT,
            `nombre` varchar(100) NOT NULL,
            `password` varchar(255) NOT NULL,
            `rol` varchar(50) NOT NULL DEFAULT 'vendedor',
            `ultimo_acceso` datetime DEFAULT NULL,
            `fecha_creacion` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_usuario`),
            UNIQUE KEY `uq_usuario_nombre` (`nombre`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
    ");

    // 6. Tabla Actividad de Usuario
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

    // Verificar si la columna 'usuario' existe en 'facturacion'
    try {
        $factCols = $pdoServer->query("SHOW COLUMNS FROM `facturacion` LIKE 'usuario'")->fetchAll();
        if (empty($factCols)) {
            $pdoServer->exec("ALTER TABLE `facturacion` ADD COLUMN `usuario` varchar(100) DEFAULT 'gomez11' AFTER `ID_stock`");
        }
    } catch (Exception $e) {
        // Ignorar
    }

    // Verificar si la columna 'fecha_creacion' existe en 'usuario'
    try {
        $userDateCols = $pdoServer->query("SHOW COLUMNS FROM `usuario` LIKE 'fecha_creacion'")->fetchAll();
        if (empty($userDateCols)) {
            $pdoServer->exec("ALTER TABLE `usuario` ADD COLUMN `fecha_creacion` datetime DEFAULT CURRENT_TIMESTAMP AFTER `ultimo_acceso`");
        }
    } catch (Exception $e) {
        // Ignorar
    }

    // Migrar o sembrar datos iniciales si no hay productos cargados
    $countProd = (int)$pdoServer->query("SELECT COUNT(*) FROM `producto`")->fetchColumn();
    if ($countProd === 0) {
        $jsonPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'inventario.json';
        if (file_exists($jsonPath)) {
            $jsonData = json_decode(file_get_contents($jsonPath) ?: '[]', true);
            if (is_array($jsonData)) {
                $catStmt = $pdoServer->prepare("INSERT INTO `categoria` (`nombre`) VALUES (:nombre)");
                $getCatStmt = $pdoServer->prepare("SELECT `ID_categoria` FROM `categoria` WHERE `nombre` = :nombre LIMIT 1");
                $prodStmt = $pdoServer->prepare("
                    INSERT INTO `producto` (`nombre`, `codigo`, `cantTotal`, `cantVendida`, `ID_categoria`, `precio`, `fase`)
                    VALUES (:nombre, :codigo, :cantTotal, :cantVendida, :ID_categoria, :precio, :fase)
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
                        ':cantTotal'    => (int)($item['cantidad'] ?? ($item['stock'] ?? 0)),
                        ':cantVendida'  => (int)($item['cantVendida'] ?? 0),
                        ':ID_categoria' => $catMap[$catName],
                        ':precio'       => (float)($item['precio'] ?? 0),
                        ':fase'         => (string)($item['fase'] ?? 'habilitado')
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
                    INSERT INTO `facturacion` (`fecha`, `cantidadVendida`, `precioFinal`, `ID_stock`, `usuario`)
                    VALUES (:fecha, :cant, :precio, :id_stock, :usuario)
                ");
                $updProdStmt = $pdoServer->prepare("
                    UPDATE `producto` SET `cantVendida` = COALESCE(`cantVendida`, 0) + :cant WHERE `ID_stock` = :id_stock
                ");

                foreach ($ventasJson as $v) {
                    if (empty($v['productos']) || !is_array($v['productos'])) continue;
                    $fechaSql = date('Y-m-d H:i:s');
                    if (!empty($v['fecha'])) {
                        $ts = strtotime((string)$v['fecha']);
                        if ($ts !== false) $fechaSql = date('Y-m-d H:i:s', $ts);
                    }
                    $usuarioVenta = (string)($v['usuario'] ?? 'gomez11');

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
                                ':id_stock' => (int)$idStock,
                                ':usuario'  => $usuarioVenta
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

    // Sembrar usuarios iniciales si está vacía
    $countUser = (int)$pdoServer->query("SELECT COUNT(*) FROM `usuario`")->fetchColumn();
    if ($countUser === 0) {
        $userJsonPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'users.json';
        if (file_exists($userJsonPath)) {
            $userJson = json_decode(file_get_contents($userJsonPath) ?: '[]', true);
            if (is_array($userJson) && !empty($userJson)) {
                $userStmt = $pdoServer->prepare("INSERT INTO `usuario` (`nombre`, `password`, `rol`, `fecha_creacion`) VALUES (:nombre, :pass, :rol, NOW())");
                foreach ($userJson as $u) {
                    $userStmt->execute([
                        ':nombre' => (string)($u['usuario'] ?? 'admin'),
                        ':pass'   => password_hash((string)($u['password'] ?? '1234'), PASSWORD_DEFAULT),
                        ':rol'    => (string)($u['role'] ?? 'vendedor')
                    ]);
                }
            }
        } else {
            $pdoServer->prepare("INSERT INTO `usuario` (`nombre`, `password`, `rol`, `fecha_creacion`) VALUES (?, ?, 'superadmin', NOW())")->execute(['gomez11', password_hash('santu99', PASSWORD_DEFAULT)]);
            $pdoServer->prepare("INSERT INTO `usuario` (`nombre`, `password`, `rol`, `fecha_creacion`) VALUES (?, ?, 'vendedor', NOW())")->execute(['vendedor_demo', password_hash('1234', PASSWORD_DEFAULT)]);
        }
    }

    // Mantener las cuentas base y sus roles sincronizados en instalaciones existentes.
    try {
        $seedUsers = [
            ['nombre' => 'gomez11', 'password' => 'santu99', 'rol' => 'superadmin'],
            ['nombre' => 'GOMEZ ADMIN', 'password' => '1234', 'rol' => 'administrador'],
            ['nombre' => 'vendedor_demo', 'password' => '1234', 'rol' => 'vendedor']
        ];
        $seedStmt = $pdoServer->prepare("
            INSERT INTO `usuario` (`nombre`, `password`, `rol`, `fecha_creacion`)
            VALUES (:nombre, :password, :rol, NOW())
            ON DUPLICATE KEY UPDATE `rol` = VALUES(`rol`)
        ");
        foreach ($seedUsers as $seedUser) {
            $seedStmt->execute([
                ':nombre' => $seedUser['nombre'],
                ':password' => password_hash($seedUser['password'], PASSWORD_DEFAULT),
                ':rol' => $seedUser['rol']
            ]);
        }
    } catch (Exception $e) {
        // La aplicación conserva el respaldo JSON si la migración no puede ejecutarse.
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

// Variables globales de conveniencia para compatibilidad
try {
    $conexion = getDBConnection();
    $pdo = $conexion;
    $conn = $conexion;
} catch (Exception $e) {
    $conexion = null;
    $pdo = null;
    $conn = null;
}
