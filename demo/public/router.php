<?php
/**
 * Entry point for PHP's built-in server:
 *   php -S 127.0.0.1:8787 -t public public/router.php
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/' || $path === '/console' || $path === '/console/') {
    header('Location: /console/index.html', true, 302);
    return true;
}

if (!str_starts_with($path, '/api/')) {
    return false; // let the built-in server serve static files from public/
}

require dirname(__DIR__) . '/src/bootstrap.php';
Api::handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
