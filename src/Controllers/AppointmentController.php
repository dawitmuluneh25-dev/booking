<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Actor;
use App\Domain\Appointment;
use App\Domain\AppointmentNotFoundException;
use App\Domain\AppointmentRepository;
use App\Domain\AppointmentStatus;
use App\Domain\AuthorisationException;
use App\Domain\ConsentRepository;
use App\Domain\DocumentRepository;
use App\Domain\Permission;
use App\Domain\ProcedureMilestone;
use App\Domain\ProcedureMilestoneException;
use App\Domain\ProcedureMilestoneRecord;
use App\Domain\ProcedureMilestoneRepository;
use App\Domain\RecordProcedureMilestone;
use App\Domain\ReferralRepository;
use App\Domain\Role;
use App\Domain\ValidationException;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\Session;
use App\Support\View;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The procedure day: today's list, one appointment, and what happened on it.
 *
 * Two records meet on this page and stay distinct:
 *
 * - the appointment's **state**, which is where the case is now and which
 *   supersedes itself with every move;
 * - its **milestones** (§15), which are what happened, when, and who recorded it,
 *   and which are never superseded at all.
 *
 * The timeline prints both, once each. A state change that a milestone explains is
 * not printed twice: the milestone is the event and the state row is the machine's
 * receipt for it. Where a state changed with no milestone behind it — a
 * cancellation, a deferral, a reschedule — the state row is all there is, and it is
 * printed.
 *
 * The permission that admits the page is `schedule.manage`, which is what the four
 * roles that work the day already hold. Which milestone a person may *record* is a
 * second question, asked per kind, and it is the same question the command asks.
 */
final class AppointmentController
{
    private const BASE = '/appointments';

    public function __construct(
        private readonly View $view,
        private readonly Gate $gate,
        private readonly AppointmentRepository $appointments,
        private readonly ProcedureMilestoneRepository $milestones,
        private readonly ReferralRepository $referrals,
        private readonly ConsentRepository $consents,
        private readonly DocumentRepository $documents,
        private readonly RecordProcedureMilestone $record,
        private readonly string $timezone,
    ) {
    }

    /** Today's list, which is what a coordination queue is. */
    public function index(Request $request): Response
    {
        $user = $this->gate->authenticate();
        $this->gate->authorize(Permission::ScheduleManage);

        $zone = new DateTimeZone($this->timezone);
        $date = $this->localDate($request, $zone);
        [$fromUtc, $toUtc] = $this->utcWindow($date, $zone);

        $appointments = $this->appointments->forDay($fromUtc, $toUtc);

        $grouped = $this->milestones->forAppointments(array_map(
            static fn (Appointment $appointment): int => $appointment->id,
            $appointments,
        ));

        $rows = [];

        foreach ($appointments as $appointment) {
            $recorded = $grouped[$appointment->id] ?? [];

            $rows[] = [
                'appointment' => $appointment,
                'recorded' => count($recorded),
                'next' => $this->nextMilestone($recorded),
                'outOfOrder' => count(array_filter(
                    $recorded,
                    static fn (ProcedureMilestoneRecord $record): bool => $record->wasRecordedOutOfOrder(),
                )),
            ];
        }

        return Response::html($this->view->render('appointments/index', [
            'title' => 'Procedure day',
            'date' => $date->format('Y-m-d'),
            'dateLabel' => $date->format('l j F Y'),
            'rows' => $rows,
            'timezone' => $this->timezone,
            'notice' => Session::pullFlash('notice'),
            'error' => Session::pullFlash('error'),
            'currentUser' => $user,
        ]));
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $this->gate->authenticate();

        return $this->render($this->findOrFail((string) ($params['reference'] ?? '')), [], null, 200);
    }

    /** @param array<string, string> $params */
    public function recordMilestone(Request $request, array $params): Response
    {
        $user = $this->gate->authenticate();

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $appointment = $this->findOrFail((string) ($params['reference'] ?? ''));
        $kind = ProcedureMilestone::tryFrom((string) $request->input('kind', ''));

        if ($kind === null) {
            return $this->render(
                $appointment,
                ['kind' => 'Choose which event happened.'],
                null,
                422,
            );
        }

        try {
            $this->record->execute(
                new Actor($user->id, $user->role),
                $appointment->reference,
                $kind,
                [
                    'note' => (string) $request->input('note', ''),
                    'out_of_order_reason' => (string) $request->input('out_of_order_reason', ''),
                ],
            );
        } catch (AuthorisationException) {
            // The form only offers what the actor may record, so reaching this is a
            // hand-edited POST. It is answered as forbidden rather than 404: the
            // appointment is not the secret, the act is.
            throw HttpException::forbidden('Your role may not record that event.');
        } catch (ValidationException $exception) {
            return $this->render($appointment, $exception->errors, null, 422);
        } catch (ProcedureMilestoneException $exception) {
            return $this->render($appointment, [], $exception->getMessage(), 422);
        }

        Session::flash('notice', $kind->label() . ' recorded for ' . $appointment->reference . '.');

        return Response::redirect(self::BASE . '/' . rawurlencode($appointment->reference), 303);
    }

    /** @param array<string, string> $fieldErrors */
    private function render(Appointment $appointment, array $fieldErrors, ?string $formError, int $status): Response
    {
        $user = $this->gate->authenticate();

        $referral = $appointment->referralId > 0
            ? $this->referrals->findById($appointment->referralId)
            : null;

        $milestones = $this->milestones->forAppointment($appointment->id);
        $history = $this->appointments->historyFor($appointment->id);

        return Response::html($this->view->render('appointments/show', [
            'title' => 'Appointment ' . $appointment->reference,
            'appointment' => $appointment,
            'referral' => $referral,
            'resources' => $this->appointments->resourcesFor($appointment->id),
            'timeline' => $this->timeline($history, $milestones),
            'milestones' => $milestones,
            'documents' => $referral === null ? [] : $this->documents->listForReferral($referral->id),
            'consent' => $referral === null ? null : $this->consents->current($referral->id),
            'recordable' => $this->recordable($appointment, $milestones),
            'timezone' => $this->timezone,
            'fieldErrors' => $fieldErrors,
            'formError' => $formError,
            'notice' => Session::pullFlash('notice'),
            'currentUser' => $user,
        ]), $status);
    }

    /**
     * One chronological list out of two records.
     *
     * A state row is dropped when a milestone recorded at the same instant produced
     * that same state: the milestone is the event, the row is its receipt. Nothing
     * else is dropped — a cancellation, a reschedule or a deferral has no milestone
     * and must stay visible.
     *
     * @param list<array{
     *     from: string|null,
     *     to: string,
     *     actorRole: string,
     *     actorName: string|null,
     *     reason: string|null,
     *     occurredAtUtc: string
     * }> $history
     * @param list<ProcedureMilestoneRecord> $milestones
     *
     * @return list<array{
     *     at: string,
     *     label: string,
     *     by: string,
     *     detail: string|null,
     *     milestone: bool,
     *     outOfOrder: bool
     * }>
     */
    private function timeline(array $history, array $milestones): array
    {
        $explained = [];

        foreach ($milestones as $milestone) {
            if ($milestone->resultingStatus !== null) {
                $explained[$milestone->occurredAtUtc . '|' . $milestone->resultingStatus->value] = true;
            }
        }

        $entries = [];

        foreach ($history as $row) {
            if (isset($explained[$row['occurredAtUtc'] . '|' . $row['to']])) {
                continue;
            }

            $status = AppointmentStatus::tryFrom($row['to']);

            $entries[] = [
                'at' => $row['occurredAtUtc'],
                'label' => 'Status: ' . ($status === null ? $row['to'] : $status->label()),
                'by' => $row['actorName'] ?? $this->roleLabel($row['actorRole']),
                'detail' => $row['reason'],
                'milestone' => false,
                'outOfOrder' => false,
            ];
        }

        foreach ($milestones as $milestone) {
            $entries[] = [
                'at' => $milestone->occurredAtUtc,
                'label' => $milestone->kind->label(),
                'by' => $milestone->recordedByName ?? $milestone->recordedByRole->label(),
                'detail' => $milestone->note,
                'milestone' => true,
                'outOfOrder' => $milestone->wasRecordedOutOfOrder(),
            ];
        }

        // Milestones before state rows at the same instant, then by time. `usort`
        // is not stable, so the sequence index is carried rather than assumed.
        $indexed = [];
        foreach ($entries as $position => $entry) {
            $indexed[] = ['position' => $position] + $entry;
        }

        usort($indexed, static function (array $left, array $right): int {
            return [$left['at'], $left['milestone'] ? 0 : 1, $left['position']]
                <=> [$right['at'], $right['milestone'] ? 0 : 1, $right['position']];
        });

        $ordered = [];
        foreach ($indexed as $entry) {
            $ordered[] = [
                'at' => (string) $entry['at'],
                'label' => (string) $entry['label'],
                'by' => (string) $entry['by'],
                'detail' => is_string($entry['detail']) ? $entry['detail'] : null,
                'milestone' => (bool) $entry['milestone'],
                'outOfOrder' => (bool) $entry['outOfOrder'],
            ];
        }

        return $ordered;
    }

    /**
     * What this person can record right now, and nothing else.
     *
     * Two gates, both the command's own: the permission for the kind, and the state
     * the appointment is in. A kind whose last record is the same kind is hidden
     * because the command would refuse it — except a consent check, where a
     * different consent record makes it a new statement.
     *
     * @param list<ProcedureMilestoneRecord> $recorded
     *
     * @return list<ProcedureMilestone>
     */
    private function recordable(Appointment $appointment, array $recorded): array
    {
        $last = $recorded === [] ? null : $recorded[count($recorded) - 1];
        $available = [];

        foreach (ProcedureMilestone::cases() as $kind) {
            if (!$this->gate->allows($kind->requiredPermission())) {
                continue;
            }

            if (!in_array($appointment->status, $kind->allowedFrom(), true)) {
                continue;
            }

            if ($last !== null && $last->kind === $kind && !$kind->namesConsentRecord()) {
                continue;
            }

            $available[] = $kind;
        }

        return $available;
    }

    /**
     * The next event the day expects, for the queue.
     *
     * Not "everything outstanding": recovery does not apply to a case that has not
     * finished, and a queue that lists five missing items on every row is a queue
     * nobody reads. The first missing event in sequence is what the person at the
     * desk acts on.
     *
     * @param list<ProcedureMilestoneRecord> $recorded
     */
    private function nextMilestone(array $recorded): ?ProcedureMilestone
    {
        $kinds = array_map(
            static fn (ProcedureMilestoneRecord $record): ProcedureMilestone => $record->kind,
            $recorded,
        );

        foreach (ProcedureMilestone::cases() as $kind) {
            if (!in_array($kind, $kinds, true)) {
                return $kind;
            }
        }

        return null;
    }

    /** @throws HttpException */
    private function findOrFail(string $reference): Appointment
    {
        $appointment = $this->appointments->findByReference(trim($reference));

        if ($appointment === null) {
            throw HttpException::notFound();
        }

        return $appointment;
    }

    private function roleLabel(string $role): string
    {
        return Role::tryFrom($role)?->label() ?? $role;
    }

    private function localDate(Request $request, DateTimeZone $zone): DateTimeImmutable
    {
        $raw = trim((string) $request->input('date', ''));

        if ($raw !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $zone);

            if ($parsed !== false && $parsed->format('Y-m-d') === $raw) {
                return $parsed;
            }
        }

        return new DateTimeImmutable('today', $zone);
    }

    /**
     * The clinic's day, as the UTC window the appointments are stored in.
     *
     * A "today" computed in UTC would cut the list at three in the morning local
     * time, which is the one hour of the day when nobody would notice until a
     * morning list came up short.
     *
     * @return array{0: string, 1: string}
     */
    private function utcWindow(DateTimeImmutable $date, DateTimeZone $zone): array
    {
        $utc = new DateTimeZone('UTC');
        $start = $date->setTimezone($utc);
        $end = $date->modify('+1 day');

        if ($end === false) {
            $end = $date;
        }

        return [$start->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s')];
    }
}
