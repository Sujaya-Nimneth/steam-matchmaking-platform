<?php
declare(strict_types=1);

/**
 * Shared security helpers: response headers, session bootstrap, JSON output
 * and same-origin enforcement for state-changing requests.
 */

/**
 * Install process-wide error handling. Errors are never displayed to the
 * client, and an uncaught Throwable becomes a generic JSON 500 so internals
 * (stack traces, SQL, paths) never leak into a response.
 */
function install_error_handlers(): void
{
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);

    set_exception_handler(static function (Throwable $e): void {
        error_log(sprintf(
            'Uncaught %s: %s in %s:%d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        echo json_encode(['error' => 'Internal server error'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    });
}

/**
 * Emit hardening headers. Safe to call more than once.
 */
function security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('Cross-Origin-Opener-Policy: same-origin');

    $csp = implode('; ', [
        "default-src 'self'",
        "base-uri 'none'",
        "object-src 'none'",
        "frame-ancestors 'none'",
        "form-action 'self'",
        "script-src 'self'",
        "style-src 'self' https://fonts.googleapis.com",
        "font-src 'self' https://fonts.gstatic.com",
        "img-src 'self' data: https://avatars.steamstatic.com https://community.cloudflare.steamstatic.com https://cdn.cloudflare.steamstatic.com",
        "connect-src 'self'",
        "manifest-src 'self'",
        "worker-src 'self'",
    ]);
    header('Content-Security-Policy: ' . $csp);

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Start a session with hardened cookie attributes.
 */
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Refuse attacker-supplied session IDs instead of adopting them.
    ini_set('session.use_strict_mode', '1');

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Send a JSON response and stop.
 */
function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Reject state-changing requests that do not originate from this host.
 *
 * Browsers always send Origin for cross-origin POSTs; same-origin requests
 * send either Origin or Referer. Requests with neither header are rejected so
 * a forged form post cannot slip through.
 */
function require_same_origin(): void
{
    $source = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';

    if ($source === '') {
        json_response(['error' => 'Missing origin'], 403);
    }

    $sourceHost = parse_url($source, PHP_URL_HOST);
    $hostHeader = $_SERVER['HTTP_HOST'] ?? '';
    $host = parse_url('http://' . $hostHeader, PHP_URL_HOST);

    if (!$sourceHost || !$host || !hash_equals(strtolower((string) $host), strtolower((string) $sourceHost))) {
        json_response(['error' => 'Cross-origin request rejected'], 403);
    }
}

/**
 * Throttle a session-scoped action.
 *
 * This is a per-session guard: it stops a runaway client or a stuck retry
 * loop, but a determined attacker can still open new sessions. Terminating
 * TLS proxies should apply IP based limits as well.
 */
function rate_limit(string $bucket, int $limit, int $windowSeconds): void
{
    $now = time();
    $key = 'rate_' . $bucket;

    $hits = array_values(array_filter(
        is_array($_SESSION[$key] ?? null) ? $_SESSION[$key] : [],
        static fn ($timestamp) => is_int($timestamp) && $timestamp > $now - $windowSeconds
    ));

    if (count($hits) >= $limit) {
        $retryAfter = max(1, $windowSeconds - ($now - $hits[0]));
        header('Retry-After: ' . $retryAfter);
        json_response(['error' => 'Too many requests. Please slow down.'], 429);
    }

    $hits[] = $now;
    $_SESSION[$key] = $hits;
}

/**
 * Require an authenticated session, returning the Steam ID.
 *
 * Sessions expire absolutely 30 days after login, so a leaked cookie has
 * a bounded lifetime.
 */
function require_auth(): string
{
    $authenticatedAt = (int) ($_SESSION['authenticated_at'] ?? 0);

    if (empty($_SESSION['steam_id']) || $authenticatedAt === 0 || time() - $authenticatedAt > 60 * 60 * 24 * 30) {
        $_SESSION = [];
        json_response(['error' => 'Not authenticated'], 401);
    }

    return (string) $_SESSION['steam_id'];
}
