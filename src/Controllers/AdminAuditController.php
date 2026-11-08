<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\AuditRepository;
use App\Domain\Permission;
use App\Domain\RetentionRepository;
use App\Domain\RetentionRun;
use App\Domain\BookingEvent;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Gate;
use App\Support\View;
use Throwable;

final class AdminAuditController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly Gate $gate,
        private readonly AuditRepository $events,
        private readonly RetentionRepository $retention,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode('/admin/audit'));
        }

        $this->gate->authorize(Permission::SystemMaintain);

        $reference = strtoupper(trim((string) $request->input('reference', '')));

        /** @var list<BookingEvent> $events */
        $events = [];
        /** @var list<RetentionRun> $runs */
        $runs = [];
        $total = 0;
        $unavailable = false;

        try {
            $events = $this->events->recent(100, $reference);
            $total = $this->events->count($reference);
            $runs = $this->retention->recentRuns(10);
        } catch (Throwable) {
            $unavailable = true;
        }

        return Response::html($this->view->render('admin/audit', [
            'title' => 'Audit trail',
            'events' => $events,
            'runs' => $runs,
            'total' => $total,
            'reference' => $reference,
            'unavailable' => $unavailable,
            'currentUser' => $this->auth->user(),
        ]), $unavailable ? 503 : 200);
    }
}
