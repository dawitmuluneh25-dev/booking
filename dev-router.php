<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in server (`composer serve`).
 *
 * Serves existing static files directly and forwards everything else to the
 * front controller, which mimics the production rewrite rule.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$file = __DIR__ . '/public' . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/public/index.php';
