<?php
declare(strict_types=1);

/**
 * Shared security helpers: response headers, session bootstrap, JSON output
 * and same-origin enforcement for state-changing requests.
 */

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
 * Require an authenticated session, returning the Steam ID.
 */
function require_auth(): string
{
    if (empty($_SESSION['steam_id'])) {
        json_response(['error' => 'Not authenticated'], 401);
    }

    return (string) $_SESSION['steam_id'];
}
