<?php

/**
 * Route table. Every route declares its own method and handler; there is no
 * implicit dispatch and no catch-all controller.
 */

declare(strict_types=1);

use App\Bootstrap\Application;
use App\Controllers\BookingController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Http\Request;

return static function (Application $app): void {
    $router = $app->router();

    $home = new HomeController($app->view());

    $booking = static fn (): BookingController => new BookingController(
        $app->view(),
        static fn () => $app->bookings(),
        $app->config()->timezone(),
    );

    $router->get('/', static fn (Request $request) => $home->index($request));

    $router->get('/bookings', static fn (Request $request) => $booking()->index($request));
    $router->get('/bookings/new', static fn (Request $request) => $booking()->create($request));
    $router->post('/bookings', static fn (Request $request) => $booking()->store($request));
    $router->get(
        '/bookings/{reference}',
        static fn (Request $request, array $params) => $booking()->show($request, $params),
    );

    $health = new HealthController(static fn () => $app->pdo());
    $router->get('/healthz', static fn (Request $request) => $health($request));
};
