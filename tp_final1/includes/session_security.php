<?php
declare(strict_types=1);

/** Configura las cookies y opciones de sesión antes de iniciar PHP session. */
function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $secure = ($https !== '' && $https !== 'off' && $https !== '0')
        || getenv('SESSION_COOKIE_SECURE') === '1';
    $current = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $current['path'] !== '' ? $current['path'] : '/',
        'domain' => $current['domain'],
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    if (!session_start() || session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('No se pudo iniciar una sesión segura.');
    }
}

/** Compara esquema, host y puerto con el origen canónico de la aplicación. */
function isSameApplicationOrigin(string $origin): bool
{
    $candidate = parse_url($origin);
    if (
        !is_array($candidate)
        || !isset($candidate['scheme'], $candidate['host'])
        || isset($candidate['path'])
        || isset($candidate['query'])
        || isset($candidate['fragment'])
    ) {
        return false;
    }

    $configuredOrigin = getenv('APP_ORIGIN');
    if ($configuredOrigin !== false && trim($configuredOrigin) !== '') {
        $expected = parse_url(trim($configuredOrigin));
    } else {
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return false;
        }
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        $scheme = ($https !== '' && $https !== 'off' && $https !== '0') ? 'https' : 'http';
        $expected = parse_url($scheme . '://' . $host);
    }

    if (
        !is_array($expected)
        || !isset($expected['scheme'], $expected['host'])
        || isset($expected['path'])
        || isset($expected['query'])
        || isset($expected['fragment'])
    ) {
        return false;
    }

    $candidateScheme = strtolower((string)$candidate['scheme']);
    $expectedScheme = strtolower((string)$expected['scheme']);
    $candidatePort = (int)($candidate['port'] ?? ($candidateScheme === 'https' ? 443 : 80));
    $expectedPort = (int)($expected['port'] ?? ($expectedScheme === 'https' ? 443 : 80));

    return in_array($candidateScheme, ['http', 'https'], true)
        && $candidateScheme === $expectedScheme
        && strtolower((string)$candidate['host']) === strtolower((string)$expected['host'])
        && $candidatePort === $expectedPort
        && !isset($candidate['user'])
        && !isset($candidate['pass']);
}

/** Rejects browser-originated cross-site requests that can change server state. */
function isSameOriginMutationRequest(): bool
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return true;
    }

    $fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if (in_array($fetchSite, ['cross-site', 'cross-origin'], true)) {
        return false;
    }

    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer === '') {
            return false;
        }
        $parts = parse_url($referer);
        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return false;
        }
        $origin = strtolower((string)$parts['scheme']) . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . (int)$parts['port'];
        }
    }

    return isSameApplicationOrigin($origin);
}

/** Clears the current session and expires its cookie using matching attributes. */
function destroySecureSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax'
        ]);
    }
    session_destroy();
}
