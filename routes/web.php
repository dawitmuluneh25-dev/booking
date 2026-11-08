<?php

/**
 * Route table. Every route declares its own method and handler; there is no
 * implicit dispatch and no catch-all controller.
 */

declare(strict_types=1);

use App\Bootstrap\Application;
use App\Controllers\AdminAuditController;
use App\Controllers\AdminController;
use App\Controllers\AdminDiagnosticsController;
use App\Controllers\AdminHoursController;
use App\Controllers\AdminUserController;
use App\Controllers\AuthController;
use App\Controllers\AvailabilityController;
use App\Controllers\BookingController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\PasswordResetController;
use App\Http\Request;

return static function (Application $app): void {
    $router = $app->router();

    $home = new HomeController($app->view(), $app->auth());
    $auth = new AuthController($app->view(), $app->auth());
    $reset = new PasswordResetController($app->view(), $app->auth());
    $adminUsers = new AdminUserController($app->view(), $app->users(), $app->auth(), $app->gate());
    $admin = new AdminController($app->view(), $app->auth(), $app->gate());

    $audit = new AdminAuditController(
        $app->view(),
        $app->auth(),
        $app->gate(),
        $app->audit(),
        $app->retentionRepository(),
    );

    $hours = new AdminHoursController(
        $app->view(),
        $app->auth(),
        $app->gate(),
        $app->calendar(),
    );

    $diagnostics = new AdminDiagnosticsController(
        $app->view(),
        $app->auth(),
        $app->gate(),
        static fn () => $app->diagnostics(),
    );

    $availability = new AvailabilityController(
        $app->view(),
        static fn () => $app->bookings(),
        $app->config()->timezone(),
        $app->auth(),
        $app->calendar(),
    );

    $booking = static fn (): BookingController => new BookingController(
        $app->view(),
        static fn () => $app->bookings(),
        $app->config()->timezone(),
        $app->auth(),
        $app->gate(),
    );

    $router->get('/', static fn (Request $request) => $home->index($request));

    $router->get('/login', static fn (Request $request) => $auth->showLogin($request));
    $router->post('/login', static fn (Request $request) => $auth->login($request));
    $router->post('/logout', static fn (Request $request) => $auth->logout($request));

    $router->get(
        '/password/reset/{token}',
        static fn (Request $request, array $params) => $reset->show($request, $params),
    );
    $router->post(
        '/password/reset/{token}',
        static fn (Request $request, array $params) => $reset->update($request, $params),
    );

    $router->get('/availability', static fn (Request $request) => $availability->index($request));

    $router->get('/bookings', static fn (Request $request) => $booking()->index($request));
    $router->get('/bookings/new', static fn (Request $request) => $booking()->create($request));
    $router->post('/bookings', static fn (Request $request) => $booking()->store($request));
    $router->get(
        '/bookings/{reference}',
        static fn (Request $request, array $params) => $booking()->show($request, $params),
    );
    $router->post(
        '/bookings/{reference}/confirm',
        static fn (Request $request, array $params) => $booking()->confirm($request, $params),
    );
    $router->post(
        '/bookings/{reference}/cancel',
        static fn (Request $request, array $params) => $booking()->cancel($request, $params),
    );

    $router->get('/admin', static fn (Request $request) => $admin->index($request));
    $router->get('/admin/users', static fn (Request $request) => $adminUsers->index($request));
    $router->get('/admin/audit', static fn (Request $request) => $audit->index($request));
    $router->get('/admin/hours', static fn (Request $request) => $hours->index($request));
    $router->post('/admin/hours', static fn (Request $request) => $hours->update($request));
    $router->get(
        '/admin/diagnostics',
        static fn (Request $request) => $diagnostics->index($request),
    );

    $health = new HealthController(static fn () => $app->pdo());
    $router->get('/healthz', static fn (Request $request) => $health($request));
};
