<?php

declare(strict_types=1);

use App\Bootstrap\Application;
use App\Http\Request;
use App\Security\Session;

// The built-in server has no document-root fallback of its own: with a router script
// it sends every request — including /assets/... — through the application, which
// answers 404 and serves every page unstyled. Handing a real file back to the server
// is what makes local render checks mean anything. This affects `php -S` only.
if (PHP_SAPI === 'cli-server') {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $requested = parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);
    $requested = is_string($requested) ? $requested : '';

    if ($requested !== '' && $requested !== '/' && is_file(__DIR__ . $requested)) {
        return false;
    }
}

// The document root on the shared host is fixed at public_html, so the application may
// live in a release directory beside it rather than in its parent. APP_ROOT names that
// directory; without it the layout is the repository's own.
$configuredRoot = getenv('APP_ROOT');

$root = is_string($configuredRoot) && $configuredRoot !== ''
    ? rtrim($configuredRoot, '/\\')
    : dirname(__DIR__);

$autoload = $root . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Dependencies are not installed.\nRun: composer install\n";
    exit;
}

require $autoload;

$app = Application::boot($root);

Session::start($app->config());

$app->handle(Request::capture())->send();
