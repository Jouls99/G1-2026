<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/conexion.php';

const BACKUP_RETENTION_DAYS = 30;

function backupDirectory(): string
{
    $configuredDirectory = getenv('SOS_BACKUP_DIR');
    if ($configuredDirectory !== false && trim($configuredDirectory) !== '') {
        return rtrim($configuredDirectory, "\\/");
    }

    $homeDirectory = getenv('USERPROFILE') ?: getenv('HOME');
    if ($homeDirectory === false || trim($homeDirectory) === '') {
        throw new RuntimeException('No se pudo determinar la carpeta personal. Configurá SOS_BACKUP_DIR.');
    }

    return rtrim($homeDirectory, "\\/") . DIRECTORY_SEPARATOR . 'SOSCosmeticosBackups';
}

function findMysqldump(): string
{
    $configuredPath = getenv('MYSQLDUMP_PATH');
    $candidates = [];
    if ($configuredPath !== false && trim($configuredPath) !== '') {
        $candidates[] = trim($configuredPath);
    }

    $xamppRoot = dirname(dirname(PHP_BINARY));
    $binaryName = PHP_OS_FAMILY === 'Windows' ? 'mysqldump.exe' : 'mysqldump';
    $candidates[] = $xamppRoot . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $binaryName;

    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException(
        'No se encontró mysqldump. Configurá MYSQLDUMP_PATH con la ruta completa a mysqldump.exe.'
    );
}

function mysqlOptionValue(string $value): string
{
    if (str_contains($value, "\n") || str_contains($value, "\r")) {
        throw new RuntimeException('La configuración de MySQL contiene un salto de línea no válido.');
    }

    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

function removeExpiredBackups(string $directory): int
{
    $expirationTime = time() - (BACKUP_RETENTION_DAYS * 86400);
    $removed = 0;

    foreach (new DirectoryIterator($directory) as $entry) {
        if (!$entry->isFile() || !preg_match('/^sos_cosmeticos_\d{8}_\d{6}\.sql$/', $entry->getFilename())) {
            continue;
        }
        if ($entry->getMTime() >= $expirationTime) {
            continue;
        }
        if (!unlink($entry->getPathname())) {
            throw new RuntimeException('No se pudo eliminar el respaldo vencido: ' . $entry->getFilename());
        }
        $removed++;
    }

    return $removed;
}

function createBackup(): string
{
    $backupDirectory = backupDirectory();
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
        throw new RuntimeException('No se pudo crear la carpeta de respaldos: ' . $backupDirectory);
    }
    if (!is_writable($backupDirectory)) {
        throw new RuntimeException('La carpeta de respaldos no permite escritura: ' . $backupDirectory);
    }

    $mysqldump = findMysqldump();
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $db = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $nonTransactionalTables = $db->query("
        SELECT `TABLE_NAME`
        FROM `information_schema`.`TABLES`
        WHERE `TABLE_SCHEMA` = DATABASE()
          AND `TABLE_TYPE` = 'BASE TABLE'
          AND COALESCE(`ENGINE`, '') <> 'InnoDB'
    ")->fetchAll(PDO::FETCH_COLUMN);
    if ($nonTransactionalTables !== []) {
        throw new RuntimeException(
            'No se creó el respaldo: todas las tablas deben usar InnoDB para obtener una copia consistente. '
            . 'Tablas incompatibles: ' . implode(', ', $nonTransactionalTables)
        );
    }

    $lockPath = $backupDirectory . DIRECTORY_SEPARATOR . '.backup.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('No se pudo abrir el archivo de bloqueo del respaldo.');
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        throw new RuntimeException('Ya hay otro respaldo en ejecución.');
    }

    $timestamp = date('Ymd_His');
    $filename = 'sos_cosmeticos_' . $timestamp . '.sql';
    $finalPath = $backupDirectory . DIRECTORY_SEPARATOR . $filename;
    $temporaryPath = $backupDirectory . DIRECTORY_SEPARATOR . '.' . $filename . '.tmp';
    $credentialsPath = tempnam(sys_get_temp_dir(), 'sos-mysql-');
    if ($credentialsPath === false) {
        flock($lock, LOCK_UN);
        fclose($lock);
        throw new RuntimeException('No se pudo crear el archivo temporal de conexión a MySQL.');
    }

    try {
        if (file_exists($finalPath)) {
            throw new RuntimeException('Ya existe un respaldo con la marca de tiempo actual.');
        }

        $credentials = "[client]\n"
            . 'host=' . mysqlOptionValue(DB_HOST) . "\n"
            . 'port=' . DB_PORT . "\n"
            . 'user=' . mysqlOptionValue(DB_USER) . "\n"
            . 'password=' . mysqlOptionValue(DB_PASS) . "\n"
            . "default-character-set=utf8mb4\n";
        if (file_put_contents($credentialsPath, $credentials, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir la configuración temporal de MySQL.');
        }
        @chmod($credentialsPath, 0600);

        $command = [
            $mysqldump,
            '--defaults-extra-file=' . $credentialsPath,
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
            '--hex-blob',
            '--databases',
            DB_NAME,
        ];
        $process = proc_open($command, [
            0 => ['file', 'NUL', 'r'],
            1 => ['file', $temporaryPath, 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar mysqldump.');
        }

        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException(
                'mysqldump terminó con error (' . $exitCode . '): ' . trim((string)$errorOutput)
            );
        }
        if (!is_file($temporaryPath) || filesize($temporaryPath) === 0) {
            throw new RuntimeException('mysqldump no generó un archivo de respaldo válido.');
        }
        if (!rename($temporaryPath, $finalPath)) {
            throw new RuntimeException('No se pudo guardar el respaldo terminado.');
        }

        $removed = removeExpiredBackups($backupDirectory);
        fwrite(STDOUT, 'Respaldo creado: ' . $finalPath . PHP_EOL);
        fwrite(STDOUT, 'Respaldos vencidos eliminados: ' . $removed . PHP_EOL);

        return $finalPath;
    } finally {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
        if (is_file($credentialsPath)) {
            unlink($credentialsPath);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

try {
    createBackup();
} catch (Throwable $error) {
    fwrite(STDERR, 'Error al crear el respaldo: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
