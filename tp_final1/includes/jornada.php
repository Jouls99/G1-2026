<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/database/conexion.php';

function registrarActividadSesion(PDO $db, array $user): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || session_id() === '') {
        throw new RuntimeException('No hay una sesión PHP activa para registrar.');
    }

    $token = hash('sha256', session_id());
    $now = date('Y-m-d H:i:s');
    $lock = $db->query("SELECT GET_LOCK('sos_cosmeticos_cierre_jornada', 5)")->fetchColumn();
    if ((int)$lock !== 1) {
        throw new RuntimeException('No se pudo registrar la sesión porque hay un cierre de jornada en curso.');
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO `sesion_activa`
                (`token_sesion`, `id_usuario`, `usuario`, `iniciada_en`, `ultima_actividad`, `activa`, `cerrada_en`)
            VALUES (:token, :user_id, :username, :started_at, :last_activity, 1, NULL)
            ON DUPLICATE KEY UPDATE
                `id_usuario` = VALUES(`id_usuario`),
                `usuario` = VALUES(`usuario`),
                `ultima_actividad` = VALUES(`ultima_actividad`),
                `activa` = 1,
                `cerrada_en` = NULL
        ");
        $stmt->execute([
            ':token' => $token,
            ':user_id' => isset($user['id']) ? (int)$user['id'] : null,
            ':username' => (string)($user['usuario'] ?? 'Usuario'),
            ':started_at' => $now,
            ':last_activity' => $now
        ]);
    } finally {
        $db->query("SELECT RELEASE_LOCK('sos_cosmeticos_cierre_jornada')");
    }
}

/**
 * Marks this session closed and archives the retained sales window only after
 * the last tracked session ends at or after 22:30 in Argentina.
 *
 * @return array{closed: bool, archived: int, pruned: int}
 */
function cerrarSesionYJornadaSiCorresponde(PDO $db, array $user): array
{
    if (session_status() !== PHP_SESSION_ACTIVE || session_id() === '') {
        throw new RuntimeException('No hay una sesión PHP activa para cerrar.');
    }

    $token = hash('sha256', session_id());
    $now = date('Y-m-d H:i:s');
    registrarActividadSesion($db, $user);
    $closeSession = $db->prepare("
        UPDATE `sesion_activa`
        SET `activa` = 0, `cerrada_en` = :closed_at, `ultima_actividad` = :last_activity
        WHERE `token_sesion` = :token
    ");
    $closeSession->execute([':closed_at' => $now, ':last_activity' => $now, ':token' => $token]);

    $businessTimezone = new DateTimeZone('America/Argentina/Buenos_Aires');
    $businessNow = new DateTimeImmutable('now', $businessTimezone);
    if ($businessNow->format('H:i') < '22:30') {
        return ['closed' => false, 'archived' => 0, 'pruned' => 0];
    }

    $lock = $db->query("SELECT GET_LOCK('sos_cosmeticos_cierre_jornada', 5)")->fetchColumn();
    if ((int)$lock !== 1) {
        throw new RuntimeException('No se pudo obtener el bloqueo para cerrar la jornada.');
    }

    try {
        $phpSessionLifetime = max(60, (int)ini_get('session.gc_maxlifetime'));
        $activeSince = date('Y-m-d H:i:s', time() - $phpSessionLifetime);
        $expireSessions = $db->prepare("
            UPDATE `sesion_activa`
            SET `activa` = 0, `cerrada_en` = :now
            WHERE `activa` = 1 AND `ultima_actividad` < :active_since
        ");
        $expireSessions->execute([':now' => $now, ':active_since' => $activeSince]);

        $countActive = $db->prepare("
            SELECT COUNT(*) FROM `sesion_activa`
            WHERE `activa` = 1 AND `ultima_actividad` >= :active_since
        ");
        $countActive->execute([':active_since' => $activeSince]);
        $activeSessions = (int)$countActive->fetchColumn();
        if ($activeSessions > 0) {
            return ['closed' => false, 'archived' => 0, 'pruned' => 0];
        }

        $storageTimezone = new DateTimeZone(date_default_timezone_get());
        $businessDayStart = $businessNow->setTime(0, 0);
        $businessDayEnd = $businessDayStart->modify('+1 day');
        $dayEnd = $businessDayEnd->setTimezone($storageTimezone)->format('Y-m-d H:i:s');
        $historyStart = $businessNow->modify('monday this week')->modify('-3 weeks')->format('Y-m-d');
        $archiveStart = $businessNow->modify('monday this week')->modify('-3 weeks')
            ->setTime(0, 0)->setTimezone($storageTimezone)->format('Y-m-d H:i:s');
        $archived = archivarVentasDeJornada($db, $archiveStart, $dayEnd);
        $pruned = podarHistorialVentas($db, $historyStart)
            + purgarVentasActivasAnteriores($db, $archiveStart);

        $audit = $db->prepare("
            INSERT INTO `cierre_jornada`
                (`fecha_jornada`, `fecha_cierre`, `usuario`, `registros_eliminados`, `registros_archivados`)
            VALUES (:day, :closed_at, :username, 0, :archived)
        ");
        $audit->execute([
            ':day' => $businessNow->format('Y-m-d'),
            ':closed_at' => $businessNow->format('Y-m-d H:i:s'),
            ':username' => (string)($user['usuario'] ?? 'Usuario'),
            ':archived' => $archived
        ]);

        return ['closed' => true, 'archived' => $archived, 'pruned' => $pruned];
    } finally {
        $db->query("SELECT RELEASE_LOCK('sos_cosmeticos_cierre_jornada')");
    }
}

function archivarVentasDeJornada(PDO $db, string $dayStart, string $dayEnd): int
{
    $archived = 0;
    do {
        $db->beginTransaction();
        try {
            $batch = $db->prepare("
                SELECT `ID_factura`
                FROM `ventas`
                WHERE `fecha` >= :day_start AND `fecha` < :day_end
                ORDER BY `fecha`, `ID_factura`
                LIMIT 200
                FOR UPDATE
            ");
            $batch->execute([':day_start' => $dayStart, ':day_end' => $dayEnd]);
            $ids = array_map('intval', $batch->fetchAll(PDO::FETCH_COLUMN));
            if ($ids === []) {
                $db->commit();
                break;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $copy = $db->prepare("
                INSERT INTO `ventas_historial`
                    (`ID_factura`, `fecha`, `cantidadVendida`, `precioFinal`, `ID_stock`, `nombre_producto`, `usuario`, `semana_inicio`)
                SELECT `ID_factura`, `fecha`, `cantidadVendida`, `precioFinal`, `ID_stock`, `nombre_producto`, `usuario`,
                       DATE_SUB(DATE(`fecha`), INTERVAL WEEKDAY(`fecha`) DAY)
                FROM `ventas`
                WHERE `ID_factura` IN ({$placeholders})
            ");
            $copy->execute($ids);

            $delete = $db->prepare("DELETE FROM `ventas` WHERE `ID_factura` IN ({$placeholders})");
            $delete->execute($ids);
            $archived += $delete->rowCount();
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    } while (count($ids) === 200);

    return $archived;
}

function podarHistorialVentas(PDO $db, string $weekStart): int
{
    $pruned = 0;
    do {
        $delete = $db->prepare("
            DELETE FROM `ventas_historial`
            WHERE `semana_inicio` < :week_start
            ORDER BY `semana_inicio`, `fecha`, `ID_factura`
            LIMIT 200
        ");
        $delete->execute([':week_start' => $weekStart]);
        $count = $delete->rowCount();
        $pruned += $count;
    } while ($count === 200);

    return $pruned;
}

function purgarVentasActivasAnteriores(PDO $db, string $oldestDate): int
{
    $deleted = 0;
    do {
        $stmt = $db->prepare("
            DELETE FROM `ventas`
            WHERE `fecha` < :oldest_date
            ORDER BY `fecha`, `ID_factura`
            LIMIT 200
        ");
        $stmt->execute([':oldest_date' => $oldestDate]);
        $count = $stmt->rowCount();
        $deleted += $count;
    } while ($count === 200);

    return $deleted;
}
