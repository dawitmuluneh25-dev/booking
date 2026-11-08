<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Gate;
use App\Support\BackupFile;
use App\Support\Diagnostics;
use App\Support\LogRecord;
use App\Support\View;
use Closure;
use Throwable;

/**
 * Operator view. Public `/healthz` stays a generic liveness probe; anything
 * that describes the schema, queue or filesystem is behind this permission.
 */
final class AdminDiagnosticsController
{
    /** @param Closure(): Diagnostics $factory */
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly Gate $gate,
        private readonly Closure $factory,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode('/admin/diagnostics'));
        }

        $this->gate->authorize(Permission::SystemMaintain);

        /** @var list<array{label: string, value: string, state: string}> $checks */
        $checks = [];
        /** @var array{pending: int, sending: int, sent: int, failed: int, dead: int, oldest_minutes: int|null}|null $outbox */
        $outbox = null;
        /** @var array<string, int> $bookings */
        $bookings = [];
        /** @var list<LogRecord> $errors */
        $errors = [];
        /** @var list<BackupFile> $backups */
        $backups = [];
        $unavailable = false;

        try {
            $diagnostics = ($this->factory)();
            $checks = $diagnostics->checks();
            $outbox = $diagnostics->outbox();
            $bookings = $diagnostics->bookingCounts();
            $errors = $diagnostics->recentErrors(20);
            $backups = $diagnostics->backups(10);
        } catch (Throwable) {
            $unavailable = true;
        }

        return Response::html($this->view->render('admin/diagnostics', [
            'title' => 'Diagnostics',
            'checks' => $checks,
            'outbox' => $outbox,
            'bookings' => $bookings,
            'errors' => $errors,
            'backups' => $backups,
            'unavailable' => $unavailable,
            'currentUser' => $this->auth->user(),
        ]), $unavailable ? 503 : 200);
    }
}
