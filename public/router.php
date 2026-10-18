<?php
declare(strict_types=1);

/**
 * Front controller for the PHP built-in server and any web server whose
 * document root is public/.
 *
 *   php -S localhost:8000 -t public public/router.php
 *
 * Requests for /api/*.php are dispatched to the api/ directory that lives
 * outside the document root, so backend code is never directly executable.
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = rawurldecode($uri);

// Never expose dotfiles or private application directories.
if (preg_match('#(^|/)\.#', $uri) || preg_match('#^/(config|db|services|tests|deploy|vendor)(/|$)#i', $uri)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    return true;
}

// Route API endpoints to the application directory outside public/.
if (preg_match('#^/api/([a-z0-9_-]+)\.php$#i', $uri, $matches)) {
    $endpoint = __DIR__ . '/../api/' . strtolower($matches[1]) . '.php';

    if (is_file($endpoint)) {
        require $endpoint;
        return true;
    }

    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found']);
    return true;
}

// Let the built-in server stream real static assets from public/.
if ($uri !== '/') {
    $file = realpath(__DIR__ . $uri);

    if (
        $file !== false
        && is_file($file)
        && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)
    ) {
        return false;
    }
}

// Everything else renders the single page application.
require __DIR__ . '/index.php';
