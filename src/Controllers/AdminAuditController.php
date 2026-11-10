<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\AuditAction;
use App\Domain\AuditEvent;
use App\Domain\AuditQuery;
use App\Domain\AuditRepository;
use App\Domain\AuditTrail;
use App\Domain\BookingEvent;
use App\Domain\Permission;
use App\Domain\RetentionRepository;
use App\Domain\RetentionRun;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Gate;
use App\Support\View;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * One page over two trails: booking lifecycle events, and the account,
 * credential and document decisions that no workflow history table can hold.
 *
 * They are shown separately rather than merged because they answer different
 * questions — "what happened to this booking" and "who was given authority" — and
 * a merged feed would make the second look like an annotation on the first.
 */
final class AdminAuditController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly Gate $gate,
        private readonly AuditRepository $events,
        private readonly AuditTrail $trail,
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
        $actor = trim((string) $request->input('actor', ''));
        $action = trim((string) $request->input('action', ''));
        $objectRef = trim((string) $request->input('object_ref', ''));
        $from = trim((string) $request->input('from', ''));
        $to = trim((string) $request->input('to', ''));

        $fromUtc = $this->dayBoundary($from, false);
        $toUtc = $this->dayBoundary($to, true);

        $query = new AuditQuery(
            actor: $actor,
            action: AuditAction::tryFrom($action)->value ?? '',
            objectType: '',
            objectRef: $objectRef,
            fromUtc: $fromUtc,
            toUtc: $toUtc,
        );

        /** @var list<BookingEvent> $events */
        $events = [];
        /** @var list<RetentionRun> $runs */
        $runs = [];
        /** @var list<AuditEvent> $auditEvents */
        $auditEvents = [];
        $total = 0;
        $auditTotal = 0;
        $unavailable = false;

        try {
            $events = $this->events->recent(100, $reference);
            $total = $this->events->count($reference);
            $runs = $this->retention->recentRuns(10);
            $auditEvents = $this->trail->search($query->withLimit(100));
            $auditTotal = $this->trail->count($query);
        } catch (Throwable) {
            $unavailable = true;
        }

        return Response::html($this->view->render('admin/audit', [
            'title' => 'Audit trail',
            'events' => $events,
            'runs' => $runs,
            'total' => $total,
            'reference' => $reference,
            'auditEvents' => $auditEvents,
            'auditTotal' => $auditTotal,
            'auditActor' => $actor,
            'auditAction' => $action,
            'auditObjectRef' => $objectRef,
            'auditFrom' => $from,
            'auditTo' => $to,
            'auditFiltered' => !$query->isEmpty(),
            'invalidDate' => ($from !== '' && $fromUtc === '') || ($to !== '' && $toUtc === ''),
            'actions' => AuditAction::cases(),
            'unavailable' => $unavailable,
            'currentUser' => $this->auth->user(),
        ]), $unavailable ? 503 : 200);
    }

    /**
     * Turns a date a person typed into a UTC instant, in the clinic's zone.
     *
     * `datetime` uses the application's configured zone (set at boot), so "9
     * November" means the clinic's 9 November and not UTC's. The end of a range is
     * exclusive, which is why the upper bound is the following midnight.
     */
    private function dayBoundary(string $date, bool $exclusiveEnd): string
    {
        if ($date === '') {
            return '';
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return '';
        }

        $utc = $parsed->setTimezone(new DateTimeZone('UTC'));

        if ($exclusiveEnd) {
            $utc = $utc->modify('+1 day');
        }

        return $utc->format('Y-m-d H:i:s');
    }
}
