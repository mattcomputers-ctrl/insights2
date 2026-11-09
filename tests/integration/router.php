<?php
/**
 * Router for PHP's built-in dev server (integration tests / local preview):
 *   php -S 0.0.0.0:8080 tests/integration/router.php   (run from the app root)
 * Serves real files from public/ directly; everything else goes through
 * the front controller, as Apache does in production.
 */
$publicDir = dirname(__DIR__, 2) . '/public';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = realpath($publicDir . $path);

if ($file !== false
    && str_starts_with($file, realpath($publicDir))
    && is_file($file)
    && pathinfo($file, PATHINFO_EXTENSION) !== 'php'
) {
    return false;   // built-in server streams the static file
}

require $publicDir . '/index.php';
