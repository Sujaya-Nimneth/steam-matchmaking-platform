<?php
declare(strict_types=1);

/**
 * Shared web entry-point bootstrap. Every public entry point (api/*.php and
 * public/index.php) starts here, so error handling, security headers and the
 * hardened session can never drift between endpoints.
 */

require __DIR__ . '/security.php';
require __DIR__ . '/db.php';
require __DIR__ . '/domain.php';

install_error_handlers();
security_headers();
start_secure_session();
