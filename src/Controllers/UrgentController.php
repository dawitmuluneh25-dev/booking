<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Actor;
use App\Domain\AdvanceUrgentCoordination;
use App\Domain\AcknowledgeUrgentCase;
use App\Domain\AuthorisationException;
use App\Domain\CloseUrgentCase;
use App\Domain\EscalateUrgentCase;
use App\Domain\Permission;
use App\Domain\RecordUrgentContactAttempt;
use App\Domain\RecordUrgentDecision;
use App\Domain\RegisterUrgentCase;
use App\Domain\Role;
use App\Domain\UrgentCase;
use App\Domain\UrgentCaseNotFoundException;
use App\Domain\UrgentCaseRepository;
use App\Domain\UrgentCoordination;
use App\Domain\UrgentDecision;
use App\Domain\UrgentDecisionType;
use App\Domain\UrgentStatus;
use App\Domain\UrgentTransitionException;
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
 * The urgent coordination surface: the board, the intake, and the four acts on a
 * case.
 *
 * Two permissions, not one. Seeing the board and claiming a case is
 * `urgent.acknowledge`, which reception holds, because WF-05 needs whoever is at
 * the desk to be able to say "I have seen this". Escalating and closing is
 * `urgent.coordinate`. The controller never decides which role may do what beyond
 * that — the transition tables on `UrgentStatus` and `UrgentCoordination` do, and
 * the domain refuses before anything is written.
 *
 * Nothing here can create an appointment. The case lives in its own table with no
 * resource, no slot and no time (WF-04); the only scheduling-shaped action in this
 * class is closure.
 */
final class UrgentController
{
    private const DEFAULTS = [
        'patient_name' => '',
        'patient_dob' => '',
        'patient_age_months' => '',
        'guardian_name' => '',
        'guardian_phone' => '',
        'guardian_relationship' => '',
        'referring_physician_name' => '',
        'referring_facility' => '',
        'referring_physician_phone' => '',
        'receiving_hospital_name' => '',
        'clinical_summary' => '',
        'imaging_summary' => '',
        'channel' => 'telephone',
        'note' => '',
        'contact_role' => 'referring_clinician',
        'contact_phone' => '',
        'contact_name' => '',
        'outcome' => 'no_answer',
        'notes' => '',
        'level' => '1',
        'reason' => '',
        'resolution_note' => '',
        'decision_type' => '',
        'rationale' => '',
    ];

    public function __construct(
        private readonly View $view,
        private readonly Gate $gate,
        private readonly UrgentCaseRepository $cases,
        private readonly RegisterUrgentCase $register,
        private readonly AcknowledgeUrgentCase $acknowledge,
        private readonly RecordUrgentContactAttempt $recordContact,
        private readonly EscalateUrgentCase $escalate,
        private readonly CloseUrgentCase $close,
        private readonly RecordUrgentDecision $decideCase,
        private readonly AdvanceUrgentCoordination $advanceCoordination,
        private readonly string $timezone,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->gate->authorize(Permission::UrgentAcknowledge);

        $rows = [];

        foreach ($this->cases->openCases(50) as $case) {
            $rows[] = [
                'case' => $case,
                'receivedAt' => $this->localTime($case->receivedAtUtc),
                'elapsed' => $this->elapsed($case->receivedAtUtc),
            ];
        }

        return Response::html($this->view->render('urgent/index', [
            'title' => 'Urgent coordination',
            'rows' => $rows,
            'canRegister' => $this->gate->allows(Permission::ReferralSubmit),
            'notice' => Session::pullFlash('notice'),
            'currentUser' => $this->gate->authenticate(),
        ]));
    }

    public function create(Request $request): Response
    {
        $this->gate->authorize(Permission::ReferralSubmit);

        return $this->intake(self::DEFAULTS, [], 200);
    }

    public function store(Request $request): Response
    {
        $this->gate->authorize(Permission::ReferralSubmit);

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $values = [];
        foreach (array_keys(self::DEFAULTS) as $field) {
            $values[$field] = (string) $request->input($field, '');
        }

        try {
            $case = $this->register->execute($this->actor(), $values);
        } catch (ValidationException $exception) {
            return $this->intake(array_merge(self::DEFAULTS, $values), $exception->errors, 422);
        } catch (AuthorisationException) {
            throw HttpException::forbidden('You are not allowed to register an urgent case.');
        }

        // The confirmation §10.5 requires: recorded is not acknowledged, and the
        // referring team is told so in the one place they will look for good news.
        Session::flash('notice', 'Urgent case ' . $case->reference . ' recorded. This is not an '
            . 'acknowledgement: continue direct contact with the receiving team until a named '
            . 'clinician confirms it.');

        return Response::redirect('/urgent-cases/' . rawurlencode($case->reference));
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $this->gate->authorize(Permission::UrgentAcknowledge);

        return $this->detail($this->find((string) ($params['reference'] ?? '')), [], null, 200);
    }

    /** @param array<string, string> $params */
    public function acknowledge(Request $request, array $params): Response
    {
        $this->gate->authorize(Permission::UrgentAcknowledge);

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $case = $this->find((string) ($params['reference'] ?? ''));
        $channel = (string) $request->input('channel', 'application');
        $note = (string) $request->input('note', '');

        try {
            $this->acknowledge->execute($this->actor(), $case->id, $channel, $note);
        } catch (ValidationException $exception) {
            return $this->detail($case, $exception->errors, null, 422);
        } catch (UrgentTransitionException $exception) {
            return $this->detail($case, [], $exception->getMessage(), 409);
        }

        Session::flash('notice', 'Acknowledged on ' . $case->reference . '.');

        return Response::redirect('/urgent-cases/' . rawurlencode($case->reference));
    }

    /** @param array<string, string> $params */
    public function contact(Request $request, array $params): Response
    {
        $this->gate->authorize(Permission::UrgentAcknowledge);

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $case = $this->find((string) ($params['reference'] ?? ''));

        $input = [
            'contact_role' => (string) $request->input('contact_role', ''),
            'contact_name' => (string) $request->input('contact_name', ''),
            'contact_phone' => (string) $request->input('contact_phone', ''),
            'channel' => (string) $request->input('channel', 'telephone'),
            'outcome' => (string) $request->input('outcome', ''),
            'notes' => (string) $request->input('notes', ''),
        ];

        try {
            $this->recordContact->execute($this->actor(), $case->id, $input);
        } catch (ValidationException $exception) {
            return $this->detail($case, $exception->errors, null, 422);
        } catch (UrgentTransitionException $exception) {
            return $this->detail($case, [], $exception->getMessage(), 409);
        }

        Session::flash('notice', 'Contact attempt recorded on ' . $case->reference . '.');

        return Response::redirect('/urgent-cases/' . rawurlencode($case->reference));
    }

    /** @param array<string, string> $params */
    public function escalate(Request $request, array $params): Response
    {
        $this->gate->authorize(Permission::UrgentCoordinate);

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $case = $this->find((string) ($params['reference'] ?? ''));
        $level = (int) (string) $request->input('level', '0');
        $reason = (string) $request->input('reason', '');

        try {
            $this->escalate->execute($this->actor(), $case->id, $level, $reason);
        } catch (ValidationException $exception) {
            return $this->detail($case, $exception->errors, null, 422);
        } catch (UrgentTransitionException $exception) {
            return $this->detail($case, [], $exception->getMessage(), 409);
        }

        Session::flash('notice', 'Escalation recorded on ' . $case->reference . '.');

        return Response::redirect('/urgent-cases/' . rawurlencode($case->reference));
    }

    /** @param array<string, string> $params */
    public function close(Request $request, array $params): Response
    {
        $this->gate->authorize(Permission::UrgentCoordinate);

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $case = $this->find((string) ($params['reference'] ?? ''));
        $note = (string) $request->input('resolution_note', '');

        try {
            $this->close->execute($this->actor(), $case->id, $note);
        } catch (ValidationException $exception) {
            return $this->detail($case, $exception->errors, null, 422);
        } catch (UrgentTransitionException $exception) {
            return $this->detail($case, [], $exception->getMessage(), 409);
        }

        Session::flash('notice', 'Case ' . $case->reference . ' closed.');

        return Response::redirect('/urgent-cases');
    }

    /** @param array<string, string> $params */
    public function coordinate(Request $request, array $params): Response
    {
        $this->gate->authorize(Permission::UrgentCoordinate);

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $case = $this->find((string) ($params['reference'] ?? ''));

        try {
            $this->advanceCoordination->execute($this->actor(), $case->id, [
                'coordination' => (string) $request->input('coordination', ''),
                'note' => (string) $request->input('coordination_note', ''),
            ]);
        } catch (ValidationException $exception) {
            return $this->detail($case, $exception->errors, null, 422);
        } catch (UrgentTransitionException $exception) {
            return $this->detail($case, [], $exception->getMessage(), 409);
        }

        Session::flash('notice', 'Coordination on ' . $case->reference . ' updated.');

        return Response::redirect('/urgent-cases/' . rawurlencode($case->reference));
    }

    /** @param array<string, string> $params */
    public function decide(Request $request, array $params): Response
    {
        $this->gate->authorize(Permission::UrgentCoordinate);

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $case = $this->find((string) ($params['reference'] ?? ''));

        try {
            $this->decideCase->execute($this->actor(), $case->id, [
                'decision_type' => (string) $request->input('decision_type', ''),
                'rationale' => (string) $request->input('rationale', ''),
            ]);
        } catch (ValidationException $exception) {
            return $this->detail($case, $exception->errors, null, 422);
        } catch (UrgentTransitionException $exception) {
            return $this->detail($case, [], $exception->getMessage(), 409);
        }

        Session::flash('notice', 'Decision recorded on ' . $case->reference . '.');

        return Response::redirect('/urgent-cases/' . rawurlencode($case->reference));
    }

    private function find(string $reference): UrgentCase
    {
        $case = $this->cases->findByReference($reference);

        if ($case === null) {
            throw new HttpException(404);
        }

        return $case;
    }

    private function actor(): Actor
    {
        $user = $this->gate->authenticate();

        return new Actor($user->id, $user->role);
    }

    /**
     * Every coordination state this actor may enter from here.
     *
     * A list rather than "the next step", because the table allows more than one:
     * an acknowledged case may go straight to resolved when the family went
     * elsewhere, and offering only `coordinating` would hide the truthful answer
     * behind an extra click that says something untrue.
     *
     * @return list<UrgentCoordination>
     */
    private function coordinationMoves(UrgentCase $case, Role $role): array
    {
        $moves = [];

        foreach ([UrgentCoordination::Coordinating, UrgentCoordination::Resolved] as $candidate) {
            if (
                $case->coordination->canTransitionTo($candidate)
                && $case->coordination->canProgressTo($candidate)
                && in_array($role, $case->coordination->allowedActors($candidate), true)
            ) {
                $moves[] = $candidate;
            }
        }

        return $moves;
    }

    /** @return list<array{decision: UrgentDecision, decidedBy: string, decidedAt: string, superseded: bool}> */
    private function decisionsFor(UrgentCase $case): array
    {
        $rows = [];

        foreach ($this->cases->urgentDecisionHistory($case->id) as $entry) {
            $rows[] = [
                'decision' => $entry['decision'],
                'decidedBy' => $entry['decidedBy'],
                'decidedAt' => $this->localTime($entry['decision']->decidedAtUtc),
                'superseded' => $entry['superseded'],
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function intake(array $values, array $errors, int $status): Response
    {
        return Response::html($this->view->render('urgent/new', [
            'title' => 'Register an urgent case',
            'values' => $values,
            'errors' => $errors,
            'currentUser' => $this->gate->authenticate(),
        ]), $status);
    }

    /**
     * @param array<string, string> $errors
     */
    private function detail(UrgentCase $case, array $errors, ?string $formError, int $status): Response
    {
        $user = $this->gate->authenticate();
        $timeline = [];

        foreach ($this->cases->timeline($case->id) as $entry) {
            $timeline[] = [
                'entry' => $entry,
                'at' => $this->localTime($entry->occurredAtUtc),
            ];
        }

        return Response::html($this->view->render('urgent/show', [
            'title' => 'Urgent case ' . $case->reference,
            'case' => $case,
            'receivedAt' => $this->localTime($case->receivedAtUtc),
            'timeline' => $timeline,
            'errors' => $errors,
            'formError' => $formError,
            'notice' => Session::pullFlash('notice'),
            'values' => self::DEFAULTS,
            // Asked of the same tables the domain refuses with, so a form is hidden
            // exactly when submitting it would be refused.
            'mayAcknowledge' => in_array(
                $user->role,
                $case->coordination->allowedActors(UrgentCoordination::Acknowledged),
                true,
            ),
            'mayClose' => in_array($user->role, $case->urgency->allowedActors(UrgentStatus::Closed), true)
                && $case->urgency->canTransitionTo(UrgentStatus::Closed),
            'mayEscalate' => in_array($user->role, $case->coordination->escalationActors(), true),
            // Only the coordination moves this actor may actually make are offered.
            'coordinationMoves' => $this->coordinationMoves($case, $user->role),
            // Only the choices that can legally move this case are offered, so the
            // form cannot suggest a decision the command would refuse.
            'decisionChoices' => array_values(array_filter(
                UrgentDecisionType::cases(),
                static function (UrgentDecisionType $type) use ($case, $user): bool {
                    if (!$type->canBeRecordedFrom($case->urgency)) {
                        return false;
                    }

                    $target = $type->resultingStatus();

                    // A coordinator may coordinate a case without deciding it, so
                    // the form offers only what their role can actually record —
                    // asked of the same table the refusal comes from.
                    return $target === null
                        ? in_array($user->role, $type->releasingRoles(), true)
                        : in_array($user->role, $case->urgency->allowedActors($target), true);
                },
            )),
            'decisions' => $this->decisionsFor($case),
            'currentUser' => $user,
        ]), $status);
    }

    /**
     * How long the case has been waiting.
     *
     * Deliberately unbounded and unitless of judgement: it reports elapsed time and
     * never says "overdue". No acknowledgement interval has been approved (LP-020),
     * so a threshold in this page would be a clinical rule invented by a template.
     */
    private function elapsed(string $receivedAtUtc): string
    {
        $from = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $receivedAtUtc, new DateTimeZone('UTC'));

        if ($from === false) {
            return '';
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $seconds = max(0, $now->getTimestamp() - $from->getTimestamp());

        if ($seconds < 60) {
            return $seconds . ' s';
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return $minutes . ' min';
        }

        $hours = intdiv($minutes, 60);

        if ($hours < 24) {
            return $hours . ' h ' . ($minutes % 60) . ' min';
        }

        return intdiv($hours, 24) . ' d ' . ($hours % 24) . ' h';
    }

    private function localTime(string $utc): string
    {
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

        if ($at === false) {
            return $utc;
        }

        return $at->setTimezone(new DateTimeZone($this->timezone))->format('D j M Y, H:i')
            . ' (' . $this->timezone . ')';
    }
}
