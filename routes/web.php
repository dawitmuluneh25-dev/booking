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
use App\Controllers\DocumentController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\PasswordResetController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\RouteAccess;

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

    $router->get('/', static fn (Request $request) => $home->index($request), RouteAccess::public('public home page'));

    $router->get(
        '/login',
        static fn (Request $request) => $auth->showLogin($request),
        RouteAccess::public('sign-in form'),
    );
    $router->post(
        '/login',
        static fn (Request $request) => $auth->login($request),
        RouteAccess::public('sign-in submission'),
    );
    $router->post('/logout', static fn (Request $request) => $auth->logout($request), RouteAccess::authenticated());

    $router->get(
        '/password/reset/{token}',
        static fn (Request $request, array $params) => $reset->show($request, $params),
        RouteAccess::public('password reset link from email'),
    );
    $router->post(
        '/password/reset/{token}',
        static fn (Request $request, array $params) => $reset->update($request, $params),
        RouteAccess::public('password reset submission'),
    );

    $router->get(
        '/availability',
        static fn (Request $request) => $availability->index($request),
        RouteAccess::public('availability before sign-in'),
    );

    $router->get(
        '/bookings',
        static fn (Request $request) => $booking()->index($request),
        RouteAccess::permission(Permission::BookingsManage),
    );
    $router->get(
        '/bookings/new',
        static fn (Request $request) => $booking()->create($request),
        RouteAccess::permission(Permission::BookingsManage),
    );
    $router->post(
        '/bookings',
        static fn (Request $request) => $booking()->store($request),
        RouteAccess::permission(Permission::BookingsManage),
    );
    $router->get(
        '/bookings/{reference}',
        static fn (Request $request, array $params) => $booking()->show($request, $params),
        RouteAccess::permission(Permission::BookingsManage),
    );
    $router->post(
        '/bookings/{reference}/confirm',
        static fn (Request $request, array $params) => $booking()->confirm($request, $params),
        RouteAccess::permission(Permission::BookingsManage),
    );
    $router->post(
        '/bookings/{reference}/cancel',
        static fn (Request $request, array $params) => $booking()->cancel($request, $params),
        RouteAccess::permission(Permission::BookingsManage),
    );

    $router->get(
        '/admin',
        static fn (Request $request) => $admin->index($request),
        RouteAccess::permission(Permission::SystemMaintain),
    );
    $router->get(
        '/admin/users',
        static fn (Request $request) => $adminUsers->index($request),
        RouteAccess::permission(Permission::UsersManage),
    );
    $router->get(
        '/admin/audit',
        static fn (Request $request) => $audit->index($request),
        RouteAccess::permission(Permission::SystemMaintain),
    );
    $router->get(
        '/admin/hours',
        static fn (Request $request) => $hours->index($request),
        RouteAccess::permission(Permission::ClinicConfigure),
    );
    $router->post(
        '/admin/hours',
        static fn (Request $request) => $hours->update($request),
        RouteAccess::permission(Permission::ClinicConfigure),
    );
    $router->get(
        '/admin/diagnostics',
        static fn (Request $request) => $diagnostics->index($request),
        RouteAccess::permission(Permission::SystemMaintain),
    );

    $documents = new DocumentController(
        $app->view(),
        $app->gate(),
        $app->logger(),
        static fn () => $app->documentWorkspace(),
        $app->config()->timezone(),
        $app->config()->baseUrl(),
        $app->config()->documentMaxBytes(),
    );

    $router->get(
        '/referrals/{reference}/documents',
        static fn (Request $request, array $params) => $documents->index($request, $params),
        RouteAccess::permission(Permission::DocumentReadMetadata),
    );
    $router->post(
        '/referrals/{reference}/documents',
        static fn (Request $request, array $params) => $documents->store($request, $params),
        RouteAccess::permission(Permission::DocumentUpload),
    );
    $router->post(
        '/referrals/{reference}/status-link',
        static fn (Request $request, array $params) => $documents->issueLink($request, $params),
        RouteAccess::permission(Permission::ReferralTriage),
    );
    $router->get(
        '/documents/{ref}/download',
        static fn (Request $request, array $params) => $documents->download($request, $params),
        RouteAccess::permission(Permission::DocumentDownload),
    );
    $router->post(
        '/documents/{ref}/review',
        static fn (Request $request, array $params) => $documents->review($request, $params),
        RouteAccess::permission(Permission::DocumentQuarantineManage),
    );

    // The token is the credential: the route is public because the point of a
    // status link is that it works without an account, and single-use because a
    // link that survives being forwarded is a leaked link.
    $router->get(
        '/r/{token}',
        static fn (Request $request, array $params) => $documents->showStatus($request, $params),
        RouteAccess::public('single-use status link; possession of the token is the credential'),
    );

    $health = new HealthController(static fn () => $app->pdo());
    $router->get(
        '/healthz',
        static fn (Request $request) => $health($request),
        RouteAccess::public('liveness probe for the host'),
    );
};
