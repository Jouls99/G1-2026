<?php
declare(strict_types=1);

// Revisa parámetros y cabeceras entrantes con un límite de volumen para detectar patrones sospechosos.
function scanRequestForMaliciousInput(): array
{
    $patterns = [
        'SQL injection' => '/\bunion\s+(?:all\s+)?select\b|\bselect\b.{0,80}\bfrom\b|\b(?:insert\s+into|drop\s+(?:table|database)|delete\s+from)\b|[\'"]\s*(?:or|and)\s+[\'"]?\w+[\'"]?\s*=\s*[\'"]?\w+|(?:--|\/\*)|\b(?:sleep|benchmark)\s*\(/i',
        'XSS' => '/<\s*script\b|<\s*\/?\s*(?:iframe|object|embed)\b|javascript\s*:|\bon[a-z]{3,}\s*=/i',
        'Command injection' => '/(?:[;&|]\s*(?:cat|curl|wget|bash|sh|powershell|cmd|nc|whoami)\b)|`[^`]+`|\$\([^)]{1,120}\)/i',
        'Path traversal' => '/(?:\.\.[\/\\\\]){2,}|%2e%2e(?:%2f|%5c)/i',
        'PHP code execution' => '/<\?(?:php|=)|\b(?:eval|assert|system|shell_exec|passthru|base64_decode)\s*\(/i'
    ];
    $findings = [];
    $examined = 0;
    $inspect = static function (mixed $value) use (&$inspect, &$findings, &$examined, $patterns): void {
        if ($examined >= 500) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                $inspect($child);
                if ($examined >= 500) {
                    break;
                }
            }
            return;
        }
        if (!is_scalar($value)) {
            return;
        }

        $examined++;
        $text = substr((string)$value, 0, 8192);
        $decodedText = rawurldecode($text);
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $text) === 1 || preg_match($pattern, $decodedText) === 1) {
                $findings[$name] = true;
            }
        }
    };

    $inspect($_GET);
    $inspect($_POST);
    $inspect($_SERVER['REQUEST_URI'] ?? '');
    $inspect($_SERVER['HTTP_USER_AGENT'] ?? '');
    $inspect($_SERVER['HTTP_REFERER'] ?? '');

    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 0 && $contentLength <= 1048576) {
        $body = file_get_contents('php://input');
        if ($body !== false) {
            $inspect($body);
        }
    }

    return array_keys($findings);
}

function logMaliciousInputAttempt(array $signals): void
{
    // Persiste solo metadatos y señales, sin guardar el contenido recibido en la solicitud.
    if ($signals === [] || !empty($GLOBALS['malicious_input_checked'])) {
        return;
    }
    $GLOBALS['malicious_input_checked'] = true;

    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'unknown'));
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $user = $_SESSION['user']['usuario'] ?? null;
    $families = implode(', ', $signals);
    $key = hash('sha256', strtolower($ip) . '|' . $script . '|' . implode('|', $signals));

    require_once dirname(__DIR__) . '/database/conexion.php';
    $db = getDBConnection();
    if ($db === null) {
        error_log('No se pudo registrar una solicitud sospechosa: MySQL no está disponible.');
        return;
    }

    try {
        $duplicate = $db->prepare("
            SELECT `id_amenaza` FROM `amenaza`
            WHERE `regla` = 'request_code'
              AND `clave` = :clave
              AND `fecha` >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ORDER BY `fecha` DESC
            LIMIT 1
        ");
        $duplicate->execute([':clave' => $key]);
        $existingId = $duplicate->fetchColumn();
        if ($existingId) {
            $update = $db->prepare("
                UPDATE `amenaza`
                SET `intentos` = `intentos` + 1, `fecha` = NOW()
                WHERE `id_amenaza` = :id
            ");
            $update->execute([':id' => $existingId]);
            return;
        }

        $now = date('Y-m-d H:i:s');
        $insert = $db->prepare("
            INSERT INTO `amenaza`
                (`id_amenaza`, `regla`, `clave`, `titulo`, `descripcion`, `usuario`, `ip`, `intentos`, `fecha`, `ventana_minutos`)
            VALUES (:id, 'request_code', :clave, :titulo, :descripcion, :usuario, :ip, 1, :fecha, 15)
        ");
        $insert->execute([
            ':id' => hash('sha256', 'request_code|' . $key . '|' . $now),
            ':clave' => $key,
            ':titulo' => 'Posible código malicioso detectado',
            ':descripcion' => "Señales detectadas: {$families}. Ruta: {$script}; método: {$method}. El contenido recibido no se almacena.",
            ':usuario' => is_string($user) ? $user : null,
            ':ip' => $ip !== '' ? substr($ip, 0, 45) : null,
            ':fecha' => $now
        ]);
    } catch (Throwable $error) {
        error_log('No se pudo guardar la alerta de posible código malicioso: ' . $error->getMessage());
    }
}

function monitorRequestForMaliciousInput(): void
{
    // Omite ejecuciones de consola y preflight para no auditar tráfico que no ejecuta endpoints.
    if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        return;
    }

    logMaliciousInputAttempt(scanRequestForMaliciousInput());
}
