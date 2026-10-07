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
 * Si MySQL no responde, retorna null para que la API informe que la base de datos no está disponible.
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
    ensureProductSubcategoryColumn($pdo);
    ensureProductCatalogFields($pdo);
    ensurePriceAdjustmentHistoryTable($pdo);
    ensureUserPermissionColumns($pdo);
    ensureUniqueAdministrativeRoles($pdo);
    ensureThreatTable($pdo);
    ensureSessionTrackingTables($pdo);
    ensureFacturacionDateIndex($pdo);
    ensureSalesLedgerTables($pdo);
    importLegacyActivityFiles($pdo);
    importLegacyThreatFile($pdo);
    syncDefaultUsers($pdo);

    return $pdo;
}

function ensureFacturacionDateIndex(PDO $pdo): void
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM `information_schema`.`statistics`
        WHERE `table_schema` = DATABASE()
          AND `table_name` = 'facturacion'
          AND `index_name` = 'idx_facturacion_fecha_id'
    ");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `facturacion` ADD INDEX `idx_facturacion_fecha_id` (`fecha`, `ID_factura`)");
    }
}

function ensureSalesLedgerTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ventas` (
            `ID_factura` int(11) NOT NULL AUTO_INCREMENT,
            `fecha` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `cantidadVendida` int(11) NOT NULL,
            `precioFinal` decimal(10,2) NOT NULL,
            `ganancia` decimal(10,2) GENERATED ALWAYS AS (`cantidadVendida` * `precioFinal`) STORED,
            `ID_stock` int(11) DEFAULT NULL,
            `nombre_producto` varchar(100) DEFAULT NULL,
            `usuario` varchar(100) DEFAULT 'gomez11',
            PRIMARY KEY (`ID_factura`),
            KEY `fk_ventas_producto` (`ID_stock`),
            KEY `idx_ventas_fecha_id` (`fecha`, `ID_factura`),
            CONSTRAINT `fk_ventas_producto`
                FOREIGN KEY (`ID_stock`) REFERENCES `producto` (`ID_stock`)
                ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ventas_historial` (
            `ID_factura` int(11) NOT NULL,
            `fecha` datetime NOT NULL,
            `cantidadVendida` int(11) NOT NULL,
            `precioFinal` decimal(10,2) NOT NULL,
            `ganancia` decimal(10,2) GENERATED ALWAYS AS (`cantidadVendida` * `precioFinal`) STORED,
            `ID_stock` int(11) DEFAULT NULL,
            `nombre_producto` varchar(100) DEFAULT NULL,
            `usuario` varchar(100) DEFAULT 'gomez11',
            `semana_inicio` date NOT NULL,
            PRIMARY KEY (`ID_factura`),
            KEY `idx_ventas_historial_semana_fecha` (`semana_inicio`, `fecha`),
            KEY `idx_ventas_historial_fecha_id` (`fecha`, `ID_factura`),
            CONSTRAINT `fk_ventas_historial_producto`
                FOREIGN KEY (`ID_stock`) REFERENCES `producto` (`ID_stock`)
                ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `migracion_datos` (
            `archivo` varchar(100) NOT NULL,
            `migrado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`archivo`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci
    ");
    $migrationName = 'facturacion_a_ventas_historial_v1';
    $check = $pdo->prepare("SELECT 1 FROM `migracion_datos` WHERE `archivo` = :name");
    $check->execute([':name' => $migrationName]);
    if ($check->fetchColumn()) {
        ensureSalesProductNameSnapshots($pdo);
        ensureSalesAutoIncrement($pdo);
        return;
    }

    $lock = $pdo->query("SELECT GET_LOCK('sos_cosmeticos_cierre_jornada', 5)")->fetchColumn();
    if ((int)$lock !== 1) {
        throw new RuntimeException('No se pudo bloquear la migración del historial de ventas.');
    }
    try {
        $check->execute([':name' => $migrationName]);
        if ($check->fetchColumn()) {
            ensureSalesProductNameSnapshots($pdo);
            return;
        }

        $timezone = new DateTimeZone('America/Argentina/Buenos_Aires');
        $today = new DateTimeImmutable('today', $timezone);
        $todayStart = $today->format('Y-m-d 00:00:00');
        $weekStart = $today->modify('monday this week')->modify('-3 weeks')->format('Y-m-d');
        $pdo->beginTransaction();
        $migrateCurrent = $pdo->prepare("
            INSERT IGNORE INTO `ventas`
                (`ID_factura`, `fecha`, `cantidadVendida`, `precioFinal`, `ID_stock`, `nombre_producto`, `usuario`)
            SELECT f.`ID_factura`, f.`fecha`, f.`cantidadVendida`, f.`precioFinal`, f.`ID_stock`, p.`nombre`, f.`usuario`
            FROM `facturacion` f
            LEFT JOIN `producto` p ON p.`ID_stock` = f.`ID_stock`
            WHERE f.`fecha` >= :today_start
        ");
        $migrateCurrent->execute([':today_start' => $todayStart]);
        $migrateHistory = $pdo->prepare("
            INSERT IGNORE INTO `ventas_historial`
                (`ID_factura`, `fecha`, `cantidadVendida`, `precioFinal`, `ID_stock`, `nombre_producto`, `usuario`, `semana_inicio`)
            SELECT f.`ID_factura`, f.`fecha`, f.`cantidadVendida`, f.`precioFinal`, f.`ID_stock`, p.`nombre`, f.`usuario`,
                   DATE_SUB(DATE(f.`fecha`), INTERVAL WEEKDAY(f.`fecha`) DAY)
            FROM `facturacion` f
            LEFT JOIN `producto` p ON p.`ID_stock` = f.`ID_stock`
            WHERE f.`fecha` >= :history_start AND f.`fecha` < :today_start
        ");
        $migrateHistory->execute([':history_start' => $weekStart, ':today_start' => $todayStart]);
        $mark = $pdo->prepare("INSERT INTO `migracion_datos` (`archivo`) VALUES (:name)");
        $mark->execute([':name' => $migrationName]);
        $pdo->commit();
        ensureSalesProductNameSnapshots($pdo);
        ensureSalesAutoIncrement($pdo);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('sos_cosmeticos_cierre_jornada')");
    }
}

function ensureSalesProductNameSnapshots(PDO $pdo): void
{
    foreach (['ventas', 'ventas_historial'] as $table) {
        if (!$pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'nombre_producto'")->fetch()) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `nombre_producto` varchar(100) DEFAULT NULL AFTER `ID_stock`");
        }
    }

    $migrationName = 'sales_product_name_snapshot_v1';
    $check = $pdo->prepare("SELECT 1 FROM `migracion_datos` WHERE `archivo` = :name");
    $check->execute([':name' => $migrationName]);
    if ($check->fetchColumn()) {
        return;
    }

    $pdo->beginTransaction();
    try {
        foreach (['ventas', 'ventas_historial'] as $table) {
            $pdo->exec("
                UPDATE `{$table}` s
                INNER JOIN `producto` p ON p.`ID_stock` = s.`ID_stock`
                SET s.`nombre_producto` = p.`nombre`
                WHERE s.`nombre_producto` IS NULL OR TRIM(s.`nombre_producto`) = ''
            ");
        }

        if ($pdo->query("SHOW TABLES LIKE 'actividad_usuario'")->fetchColumn()) {
            $activities = $pdo->query("
                SELECT `detalles`
                FROM `actividad_usuario`
                WHERE `tipo_accion` = 'venta_registrada' AND `detalles` IS NOT NULL
                ORDER BY `id_actividad` DESC
            ");
            $updateActive = $pdo->prepare("
                UPDATE `ventas`
                SET `nombre_producto` = :name
                WHERE `ID_factura` = :id AND (`nombre_producto` IS NULL OR TRIM(`nombre_producto`) = '')
            ");
            $updateHistory = $pdo->prepare("
                UPDATE `ventas_historial`
                SET `nombre_producto` = :name
                WHERE `ID_factura` = :id AND (`nombre_producto` IS NULL OR TRIM(`nombre_producto`) = '')
            ");
            foreach ($activities->fetchAll(PDO::FETCH_COLUMN) as $detailsJson) {
                $details = json_decode((string)$detailsJson, true);
                if (!is_array($details) || !is_array($details['factura_ids'] ?? null) || !is_array($details['productos'] ?? null)) {
                    continue;
                }
                foreach ($details['factura_ids'] as $index => $invoiceId) {
                    $productDescription = $details['productos'][$index] ?? null;
                    if (!is_scalar($productDescription)
                        || !preg_match('/\A(.+?)\s+\(x\d+\)\z/u', trim((string)$productDescription), $matches)) {
                        continue;
                    }
                    $name = trim($matches[1]);
                    if ($name === '' || !filter_var($invoiceId, FILTER_VALIDATE_INT)) {
                        continue;
                    }
                    $params = [':name' => $name, ':id' => (int)$invoiceId];
                    $updateActive->execute($params);
                    $updateHistory->execute($params);
                }
            }
        }

        $mark = $pdo->prepare("INSERT INTO `migracion_datos` (`archivo`) VALUES (:name)");
        $mark->execute([':name' => $migrationName]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function ensureSalesAutoIncrement(PDO $pdo): void
{
    $maxId = (int)$pdo->query("
        SELECT GREATEST(
            COALESCE((SELECT MAX(`ID_factura`) FROM `ventas`), 0),
            COALESCE((SELECT MAX(`ID_factura`) FROM `ventas_historial`), 0),
            COALESCE((SELECT MAX(`ID_factura`) FROM `facturacion`), 0)
        )
    ")->fetchColumn();
    $currentNextId = $pdo->query("
        SELECT `AUTO_INCREMENT`
        FROM `information_schema`.`tables`
        WHERE `table_schema` = DATABASE() AND `table_name` = 'ventas'
    ")->fetchColumn();
    if ($maxId > 0 && ($currentNextId === null || (int)$currentNextId <= $maxId)) {
        $nextId = $maxId + 1;
        $pdo->exec("ALTER TABLE `ventas` AUTO_INCREMENT = {$nextId}");
    }
}

function ensureSessionTrackingTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `sesion_activa` (
            `token_sesion` char(64) NOT NULL,
            `id_usuario` int(11) DEFAULT NULL,
            `usuario` varchar(100) NOT NULL,
            `iniciada_en` datetime NOT NULL,
            `ultima_actividad` datetime NOT NULL,
            `activa` tinyint(1) NOT NULL DEFAULT 1,
            `cerrada_en` datetime DEFAULT NULL,
            PRIMARY KEY (`token_sesion`),
            KEY `idx_sesion_activa_ultima_actividad` (`activa`, `ultima_actividad`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `cierre_jornada` (
            `id_cierre` bigint(20) NOT NULL AUTO_INCREMENT,
            `fecha_jornada` date NOT NULL,
            `fecha_cierre` datetime NOT NULL,
            `usuario` varchar(100) NOT NULL,
            `registros_eliminados` int(11) NOT NULL DEFAULT 0,
            `registros_archivados` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id_cierre`),
            KEY `idx_cierre_jornada_fecha` (`fecha_jornada`, `fecha_cierre`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci
    ");
    $archiveColumn = $pdo->query("SHOW COLUMNS FROM `cierre_jornada` LIKE 'registros_archivados'")->fetch();
    if (!$archiveColumn) {
        $pdo->exec("ALTER TABLE `cierre_jornada` ADD COLUMN `registros_archivados` int(11) NOT NULL DEFAULT 0 AFTER `registros_eliminados`");
    }
}

/** Persiste las alertas de seguridad generadas a partir de inicios de sesión fallidos. */
function ensureThreatTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `amenaza` (
            `id_amenaza` char(64) NOT NULL,
            `regla` varchar(50) NOT NULL,
            `clave` varchar(255) NOT NULL,
            `titulo` varchar(150) NOT NULL,
            `descripcion` text NOT NULL,
            `usuario` varchar(100) DEFAULT NULL,
            `ip` varchar(45) DEFAULT NULL,
            `intentos` int(11) NOT NULL,
            `fecha` datetime NOT NULL,
            `ventana_minutos` int(11) NOT NULL,
            PRIMARY KEY (`id_amenaza`),
            KEY `idx_amenaza_fecha` (`fecha`),
            KEY `idx_amenaza_regla_clave_fecha` (`regla`, `clave`, `fecha`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci
    ");
}

/** Importa una sola vez los registros de auditoría históricos de los archivos JSON. */
function importLegacyActivityFiles(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `migracion_datos` (
            `archivo` varchar(100) NOT NULL,
            `migrado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`archivo`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci
    ");
    $check = $pdo->prepare("SELECT 1 FROM `migracion_datos` WHERE `archivo` = :archivo");
    $mark = $pdo->prepare("INSERT INTO `migracion_datos` (`archivo`) VALUES (:archivo)");
    $exists = $pdo->prepare("
        SELECT 1 FROM `actividad_usuario`
        WHERE `id_usuario` <=> :id_usuario
          AND `usuario` = :usuario
          AND `tipo_accion` = :tipo
          AND `descripcion` = :descripcion
          AND `detalles` <=> :detalles
          AND `fecha` = :fecha
        LIMIT 1
    ");
    $insert = $pdo->prepare("
        INSERT INTO `actividad_usuario`
            (`id_usuario`, `usuario`, `tipo_accion`, `descripcion`, `detalles`, `fecha`)
        VALUES (:id_usuario, :usuario, :tipo, :descripcion, :detalles, :fecha)
    ");

    foreach (['actividad_usuarios.json', 'actividad_ventas.json', 'actividades.json'] as $filename) {
        $check->execute([':archivo' => $filename]);
        if ($check->fetchColumn()) {
            continue;
        }

        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $filename;
        $records = [];
        if (is_file($path)) {
            $contents = file_get_contents($path);
            $decoded = $contents === false ? null : json_decode($contents, true);
            if (!is_array($decoded)) {
                throw new RuntimeException("No se pudo leer el archivo histórico {$filename}.");
            }
            $records = $decoded;
        }

        $pdo->beginTransaction();
        try {
                foreach ($records as $record) {
                    if (!is_array($record)) {
                        continue;
                    }
                    $date = strtotime((string)($record['fecha'] ?? ''));
                    $fecha = date('Y-m-d H:i:s', $date === false ? time() : $date);
                    $details = $record['detalles'] ?? null;
                    $detailsJson = $details === null
                        ? null
                        : (is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_UNICODE));
                    $params = [
                        ':id_usuario' => isset($record['id_usuario']) ? (int)$record['id_usuario'] : null,
                        ':usuario' => (string)($record['usuario'] ?? 'Sistema'),
                        ':tipo' => (string)($record['tipo_accion'] ?? 'actividad'),
                        ':descripcion' => (string)($record['descripcion'] ?? ''),
                        ':detalles' => $detailsJson,
                        ':fecha' => $fecha
                    ];
                    $exists->execute($params);
                    if (!$exists->fetchColumn()) {
                        $insert->execute($params);
                    }
                }
                $mark->execute([':archivo' => $filename]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
        }
    }
}

/** Importa una sola vez las alertas de seguridad históricas del archivo JSON. */
function importLegacyThreatFile(PDO $pdo): void
{
    $filename = 'amenazas.json';
    $check = $pdo->prepare("SELECT 1 FROM `migracion_datos` WHERE `archivo` = :archivo");
    $check->execute([':archivo' => $filename]);
    if ($check->fetchColumn()) {
        return;
    }

    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $filename;
    $records = [];
    if (is_file($path)) {
        $contents = file_get_contents($path);
        $decoded = $contents === false ? null : json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("No se pudo leer el archivo histórico {$filename}.");
        }
        $records = $decoded;
    }

    $insert = $pdo->prepare("
        INSERT IGNORE INTO `amenaza`
            (`id_amenaza`, `regla`, `clave`, `titulo`, `descripcion`, `usuario`, `ip`, `intentos`, `fecha`, `ventana_minutos`)
        VALUES (:id, :regla, :clave, :titulo, :descripcion, :usuario, :ip, :intentos, :fecha, :ventana)
    ");
    $mark = $pdo->prepare("INSERT INTO `migracion_datos` (`archivo`) VALUES (:archivo)");
    $pdo->beginTransaction();
    try {
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $timestamp = strtotime((string)($record['fecha'] ?? ''));
            $id = (string)($record['id'] ?? '');
            if (strlen($id) !== 64) {
                $id = hash('sha256', json_encode($record, JSON_UNESCAPED_UNICODE) ?: serialize($record));
            }
            $insert->execute([
                ':id' => $id,
                ':regla' => (string)($record['regla'] ?? 'legacy'),
                ':clave' => (string)($record['clave'] ?? $id),
                ':titulo' => (string)($record['titulo'] ?? 'Alerta histórica'),
                ':descripcion' => (string)($record['descripcion'] ?? ''),
                ':usuario' => isset($record['usuario']) ? (string)$record['usuario'] : null,
                ':ip' => isset($record['ip']) ? (string)$record['ip'] : null,
                ':intentos' => (int)($record['intentos'] ?? 0),
                ':fecha' => date('Y-m-d H:i:s', $timestamp === false ? time() : $timestamp),
                ':ventana' => (int)($record['ventana_minutos'] ?? 15)
            ]);
        }
        $mark->execute([':archivo' => $filename]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Asegura que los permisos delegables existan en cuentas de usuario. */
function ensureUserPermissionColumns(PDO $pdo): void
{
    $permissions = [
        'puede_registrar_stock' => "ALTER TABLE `usuario` ADD COLUMN `puede_registrar_stock` tinyint(1) NOT NULL DEFAULT 0",
        'puede_modificar_informes' => "ALTER TABLE `usuario` ADD COLUMN `puede_modificar_informes` tinyint(1) NOT NULL DEFAULT 0"
    ];

    foreach ($permissions as $column => $sql) {
        $columnInfo = $pdo->query("SHOW COLUMNS FROM `usuario` LIKE '{$column}'")->fetch();
        if (!$columnInfo) {
            $pdo->exec($sql);
        }
    }
}

/** Asegura que solo exista una cuenta Administrador y una Super Administrador. */
function ensureUniqueAdministrativeRoles(PDO $pdo): void
{
    $roleSlots = [
        'slot_administrador' => "ALTER TABLE `usuario` ADD COLUMN `slot_administrador` tinyint(1) GENERATED ALWAYS AS (CASE WHEN LOWER(TRIM(`rol`)) IN ('administrador', 'admin') THEN 1 ELSE NULL END) STORED",
        'slot_superadmin' => "ALTER TABLE `usuario` ADD COLUMN `slot_superadmin` tinyint(1) GENERATED ALWAYS AS (CASE WHEN LOWER(TRIM(`rol`)) IN ('superadmin', 'super administrador', 'super_admin', 'super-admin') THEN 1 ELSE NULL END) STORED"
    ];

    foreach ($roleSlots as $column => $sql) {
        $columnInfo = $pdo->query("SHOW COLUMNS FROM `usuario` LIKE '{$column}'")->fetch();
        if (!$columnInfo) {
            $pdo->exec($sql);
        }
    }

    $indexes = [
        'uq_usuario_unico_administrador' => 'slot_administrador',
        'uq_usuario_unico_superadmin' => 'slot_superadmin'
    ];
    foreach ($indexes as $index => $column) {
        $indexInfo = $pdo->query("SHOW INDEX FROM `usuario` WHERE `Key_name` = '{$index}'")->fetch();
        if (!$indexInfo) {
            try {
                $pdo->exec("ALTER TABLE `usuario` ADD UNIQUE KEY `{$index}` (`{$column}`)");
            } catch (Exception $e) {
                error_log("No se pudo crear la restricción {$index}: " . $e->getMessage());
            }
        }
    }
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

/** Asegura que cada producto conserve su tipo/subcategoría en MySQL. */
function ensureProductSubcategoryColumn(PDO $pdo): void
{
    $column = $pdo->query("SHOW COLUMNS FROM `producto` LIKE 'subcategoria'")->fetch();
    if (!$column) {
        $pdo->exec("ALTER TABLE `producto` ADD COLUMN `subcategoria` varchar(100) DEFAULT NULL AFTER `ID_categoria`");
        $inventoryPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'inventario.json';
        if (is_file($inventoryPath)) {
            $inventory = json_decode(file_get_contents($inventoryPath) ?: '[]', true);
            if (is_array($inventory)) {
                $updateByCode = $pdo->prepare("UPDATE `producto` SET `subcategoria` = :subcategoria WHERE LOWER(`codigo`) = LOWER(:codigo)");
                $updateById = $pdo->prepare("UPDATE `producto` SET `subcategoria` = :subcategoria WHERE `ID_stock` = :id");
                foreach ($inventory as $item) {
                    if (!is_array($item) || empty($item['subcategoria'])) {
                        continue;
                    }
                    $subcategoria = trim((string)$item['subcategoria']);
                    if (!empty($item['codigo'])) {
                        $updateByCode->execute([
                            ':subcategoria' => $subcategoria,
                            ':codigo' => trim((string)$item['codigo'])
                        ]);
                    } elseif (!empty($item['ID_stock']) || !empty($item['id'])) {
                        $updateById->execute([
                            ':subcategoria' => $subcategoria,
                            ':id' => (int)($item['ID_stock'] ?? $item['id'])
                        ]);
                    }
                }
            }
        }
    }
}

/** Adds brand/secondary name fields and normalizes product subcategories by category. */
function ensureProductCatalogFields(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `sub_categoria` (
            `ID_sub_categoria` int(11) NOT NULL AUTO_INCREMENT,
            `ID_categoria` int(11) NOT NULL,
            `nombre` varchar(100) NOT NULL,
            PRIMARY KEY (`ID_sub_categoria`),
            UNIQUE KEY `uq_sub_categoria_nombre` (`ID_categoria`, `nombre`),
            CONSTRAINT `fk_sub_categoria_categoria`
                FOREIGN KEY (`ID_categoria`) REFERENCES `categoria` (`ID_categoria`)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci
    ");

    $columns = [
        'marca' => "ALTER TABLE `producto` ADD COLUMN `marca` varchar(100) DEFAULT NULL AFTER `nombre`",
        'sub_nombre' => "ALTER TABLE `producto` ADD COLUMN `sub_nombre` varchar(100) DEFAULT NULL AFTER `marca`",
        'ID_sub_categoria' => "ALTER TABLE `producto` ADD COLUMN `ID_sub_categoria` int(11) DEFAULT NULL AFTER `subcategoria`"
    ];
    foreach ($columns as $column => $sql) {
        if (!$pdo->query("SHOW COLUMNS FROM `producto` LIKE '{$column}'")->fetch()) {
            $pdo->exec($sql);
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `migracion_datos` (
            `archivo` varchar(100) NOT NULL,
            `migrado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`archivo`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci
    ");
    $migrationName = 'producto_subcategorias_v1';
    $migrationCheck = $pdo->prepare("SELECT 1 FROM `migracion_datos` WHERE `archivo` = :archivo");
    $migrationCheck->execute([':archivo' => $migrationName]);
    if (!$migrationCheck->fetchColumn()) {
        $pdo->beginTransaction();
        try {
            $pdo->exec("
                INSERT IGNORE INTO `sub_categoria` (`ID_categoria`, `nombre`)
                SELECT DISTINCT `ID_categoria`, TRIM(`subcategoria`)
                FROM `producto`
                WHERE `ID_categoria` IS NOT NULL
                  AND `subcategoria` IS NOT NULL
                  AND TRIM(`subcategoria`) <> ''
            ");
            $pdo->exec("
                UPDATE `producto` p
                INNER JOIN `sub_categoria` sc
                    ON sc.`ID_categoria` = p.`ID_categoria`
                   AND LOWER(sc.`nombre`) = LOWER(TRIM(p.`subcategoria`))
                SET p.`ID_sub_categoria` = sc.`ID_sub_categoria`
                WHERE p.`ID_sub_categoria` IS NULL
            ");
            $migrationMark = $pdo->prepare("INSERT IGNORE INTO `migracion_datos` (`archivo`) VALUES (:archivo)");
            $migrationMark->execute([':archivo' => $migrationName]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    $foreignKey = $pdo->prepare("
        SELECT 1
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'producto'
          AND CONSTRAINT_NAME = 'fk_producto_sub_categoria'
        LIMIT 1
    ");
    $foreignKey->execute();
    if (!$foreignKey->fetchColumn()) {
        $pdo->exec("
            ALTER TABLE `producto`
            ADD CONSTRAINT `fk_producto_sub_categoria`
            FOREIGN KEY (`ID_sub_categoria`) REFERENCES `sub_categoria` (`ID_sub_categoria`)
            ON DELETE SET NULL ON UPDATE CASCADE
        ");
    }
}

/** Crea el historial persistente de cambios de precios por lote. */
function ensurePriceAdjustmentHistoryTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `historial_ajuste_precio` (
            `id_ajuste` int(11) NOT NULL AUTO_INCREMENT,
            `fecha` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `usuario` varchar(100) NOT NULL,
            `alcance` varchar(30) NOT NULL,
            `categoria` varchar(255) DEFAULT NULL,
            `tipo` varchar(255) DEFAULT NULL,
            `nombre_producto` varchar(100) DEFAULT NULL,
            `tipo_ajuste` varchar(20) NOT NULL,
            `valor` decimal(12,2) NOT NULL,
            `cantidad_productos` int(11) NOT NULL,
            PRIMARY KEY (`id_ajuste`),
            KEY `idx_historial_ajuste_fecha` (`fecha`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci
    ");
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
            ['nombre' => 'GOMEZ ADMIN', 'password' => '1234', 'rol' => 'administrador']
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
        error_log('No se pudieron sincronizar las cuentas iniciales en MySQL: ' . $e->getMessage());
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

    $pdoServer->exec("
        CREATE TABLE IF NOT EXISTS `sub_categoria` (
            `ID_sub_categoria` int(11) NOT NULL AUTO_INCREMENT,
            `ID_categoria` int(11) NOT NULL,
            `nombre` varchar(100) NOT NULL,
            PRIMARY KEY (`ID_sub_categoria`),
            UNIQUE KEY `uq_sub_categoria_nombre` (`ID_categoria`, `nombre`),
            CONSTRAINT `fk_sub_categoria_categoria`
                FOREIGN KEY (`ID_categoria`) REFERENCES `categoria` (`ID_categoria`)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
    ");

    // 3. Tabla Producto
    $pdoServer->exec("
        CREATE TABLE IF NOT EXISTS `producto` (
            `ID_stock` int(11) NOT NULL AUTO_INCREMENT,
            `nombre` varchar(100) NOT NULL,
            `marca` varchar(100) DEFAULT NULL,
            `sub_nombre` varchar(100) DEFAULT NULL,
            `codigo` varchar(100) DEFAULT NULL,
            `cantTotal` int(11) DEFAULT 0,
            `cantVendida` int(11) DEFAULT 0,
            `ID_categoria` int(11) DEFAULT NULL,
            `subcategoria` varchar(100) DEFAULT NULL,
            `ID_sub_categoria` int(11) DEFAULT NULL,
            `precio` decimal(10,2) DEFAULT 0.00,
            PRIMARY KEY (`ID_stock`),
            KEY `fk_producto_categoria` (`ID_categoria`),
            KEY `fk_producto_sub_categoria` (`ID_sub_categoria`),
            CONSTRAINT `fk_producto_categoria`
                FOREIGN KEY (`ID_categoria`) REFERENCES `categoria` (`ID_categoria`)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_producto_sub_categoria`
                FOREIGN KEY (`ID_sub_categoria`) REFERENCES `sub_categoria` (`ID_sub_categoria`)
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
            `puede_registrar_stock` tinyint(1) NOT NULL DEFAULT 0,
            `puede_modificar_informes` tinyint(1) NOT NULL DEFAULT 0,
            `slot_administrador` tinyint(1) GENERATED ALWAYS AS (CASE WHEN LOWER(TRIM(`rol`)) IN ('administrador', 'admin') THEN 1 ELSE NULL END) STORED,
            `slot_superadmin` tinyint(1) GENERATED ALWAYS AS (CASE WHEN LOWER(TRIM(`rol`)) IN ('superadmin', 'super administrador', 'super_admin', 'super-admin') THEN 1 ELSE NULL END) STORED,
            `ultimo_acceso` datetime DEFAULT NULL,
            `fecha_creacion` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_usuario`),
            UNIQUE KEY `uq_usuario_unico_administrador` (`slot_administrador`),
            UNIQUE KEY `uq_usuario_unico_superadmin` (`slot_superadmin`),
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

    $pdoServer->exec("
        CREATE TABLE IF NOT EXISTS `historial_ajuste_precio` (
            `id_ajuste` int(11) NOT NULL AUTO_INCREMENT,
            `fecha` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `usuario` varchar(100) NOT NULL,
            `alcance` varchar(30) NOT NULL,
            `categoria` varchar(255) DEFAULT NULL,
            `tipo` varchar(255) DEFAULT NULL,
            `nombre_producto` varchar(100) DEFAULT NULL,
            `tipo_ajuste` varchar(20) NOT NULL,
            `valor` decimal(12,2) NOT NULL,
            `cantidad_productos` int(11) NOT NULL,
            PRIMARY KEY (`id_ajuste`),
            KEY `idx_historial_ajuste_fecha` (`fecha`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
    ");

    $subcategoryColumn = $pdoServer->query("SHOW COLUMNS FROM `producto` LIKE 'subcategoria'")->fetch();
    if (!$subcategoryColumn) {
        $pdoServer->exec("ALTER TABLE `producto` ADD COLUMN `subcategoria` varchar(100) DEFAULT NULL AFTER `ID_categoria`");
    }

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
        $jsonPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'inventario.json';
        if (file_exists($jsonPath)) {
            $jsonData = json_decode(file_get_contents($jsonPath) ?: '[]', true);
            if (is_array($jsonData)) {
                $catStmt = $pdoServer->prepare("INSERT INTO `categoria` (`nombre`) VALUES (:nombre)");
                $getCatStmt = $pdoServer->prepare("SELECT `ID_categoria` FROM `categoria` WHERE `nombre` = :nombre LIMIT 1");
                $prodStmt = $pdoServer->prepare("
                    INSERT INTO `producto` (`nombre`, `codigo`, `cantTotal`, `cantVendida`, `ID_categoria`, `subcategoria`, `precio`, `fase`)
                    VALUES (:nombre, :codigo, :cantTotal, :cantVendida, :ID_categoria, :subcategoria, :precio, :fase)
                ");

                $catMap = [];
                foreach ($jsonData as $item) {
                    $catName = trim((string)($item['categoria'] ?? ''));
                    $reservedCategory = preg_match('/\Ageneral\z/iu', $catName) === 1
                        || preg_match('/\As[ií]n categor[ií]a\z/iu', $catName) === 1;
                    if ($catName === '' || $reservedCategory) {
                        throw new RuntimeException('No se puede importar un producto sin una categoría válida.');
                    }

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
                        ':subcategoria' => !empty($item['subcategoria']) ? trim((string)$item['subcategoria']) : null,
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
        $ventasJsonPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'ventas.json';
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
        $userJsonPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'users.json';
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
        }
    }

    // Mantener las cuentas base y sus roles sincronizados en instalaciones existentes.
    try {
        $seedUsers = [
            ['nombre' => 'gomez11', 'password' => 'santu99', 'rol' => 'superadmin'],
            ['nombre' => 'GOMEZ ADMIN', 'password' => '1234', 'rol' => 'administrador']
        ];
        $seedStmt = $pdoServer->prepare("
            INSERT INTO `usuario` (`nombre`, `password`, `rol`, `fecha_creacion`)
            VALUES (:nombre, :password, :rol, NOW())
            ON DUPLICATE KEY UPDATE `id_usuario` = `id_usuario`
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
