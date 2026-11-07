<?php

declare(strict_types=1);

use App\Bootstrap\Application;
use App\Http\Request;
use App\Security\Session;

$root = dirname(__DIR__);
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
