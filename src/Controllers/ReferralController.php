<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Actor;
use App\Domain\ApproveForScheduling;
use App\Domain\AuthorisationException;
use App\Domain\ClinicalDecisionRecord;
use App\Domain\ClinicalPriorityChange;
use App\Domain\AllergyStatus;
use App\Domain\AnticoagulantStatus;
use App\Domain\AssessmentNotPermittedException;
use App\Domain\ClinicalAssessmentRepository;
use App\Domain\ConsentNotFoundException;
use App\Domain\ImagingModality;
use App\Domain\PregnancyStatus;
use App\Domain\RecordClinicalAssessment;
use App\Domain\VerifyGuardianAuthority;
use App\Domain\ConsentNotPermittedException;
use App\Domain\ConsentOutcome;
use App\Domain\ConsentParticipant;
use App\Domain\ConsentRepository;
use App\Domain\RecordProcedureConsent;
use App\Domain\WithdrawConsent;
use App\Domain\Permission;
use App\Domain\ReassignClinicalPriority;
use App\Domain\Priority;
use App\Domain\Procedure;
use App\Domain\ProcedureRepository;
use App\Domain\Referral;
use App\Domain\ReferralNotFoundException;
use App\Domain\ReferralRepository;
use App\Domain\ReferralStatus;
use App\Domain\ReferralTransitionException;
use App\Domain\SaveReferralDraft;
use App\Domain\SubmitDraft;
use App\Domain\SubmitElectiveReferral;
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
use PDOException;

/**
 * The referral front door: the form a referrer fills in, and the queue the desk
 * works from.
 *
 * Nothing here decides a status. The form posts fields, `SubmitElectiveReferral`
 * validates and records the referral, and the redirect goes to the referral that
 * was actually stored — not to a success page that could be shown for a
 * submission that failed.
 */
final class ReferralController
{
    /** The fields the command accepts, in the order the form presents them. */
    private const FIELDS = [
        'patient_name',
        'patient_phone',
        'patient_dob',
        'guardian_name',
        'guardian_phone',
        'guardian_relationship',
        'procedure_code',
        'requested_urgency',
        'clinical_summary',
        'target_site',
        'referring_physician_name',
        'referring_facility',
        'referring_physician_phone',
    ];

    /**
     * The approved requestable categories (§13.1, A-08) as value => label.
     *
     * The form offers what the model offers. It no longer hard-codes a list that
     * can drift from the CHECK constraint the value is stored under.
     *
     * @return array<string, string>
     */
    private static function urgencies(): array
    {
        $options = [];

        foreach (Priority::requestable() as $priority) {
            $options[$priority->value] = $priority->label();
        }

        return $options;
    }

    public function __construct(
        private readonly View $view,
        private readonly Gate $gate,
        private readonly SubmitElectiveReferral $submit,
        private readonly SaveReferralDraft $drafts,
        private readonly SubmitDraft $submitDraft,
        private readonly ReferralRepository $referrals,
        private readonly ProcedureRepository $procedures,
        private readonly ApproveForScheduling $release,
        private readonly ReassignClinicalPriority $reassign,
        private readonly VerifyGuardianAuthority $verifyGuardianCommand,
        private readonly ClinicalAssessmentRepository $assessments,
        private readonly RecordClinicalAssessment $recordAssessmentCommand,
        private readonly ConsentRepository $consents,
        private readonly RecordProcedureConsent $recordConsentCommand,
        private readonly WithdrawConsent $withdrawConsentCommand,
        private readonly string $timezone,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->gate->authorize(Permission::ReferralTriage);

        return Response::html($this->view->render('referrals/index', [
            'title' => 'Referral queue',
            'referrals' => $this->referrals->queued(100),
            'notice' => Session::pullFlash('notice'),
            'errors' => Session::pullFlash('errors') ?? [],
            'currentUser' => $this->gate->authenticate(),
        ]));
    }

    public function create(Request $request): Response
    {
        $this->gate->authorize(Permission::ReferralSubmit);

        return $this->form([], [], 200);
    }

    public function store(Request $request): Response
    {
        $this->gate->authorize(Permission::ReferralSubmit);
        $this->assertCsrf($request);

        $user = $this->gate->authenticate();
        $input = $this->input($request);

        try {
            $referral = $this->submit->execute(
                new Actor($user->id, $user->role),
                $input,
                null,
                $this->idempotencyKey($request),
            );
        } catch (ValidationException $exception) {
            return $this->form($input, $exception->errors, 422);
        } catch (AuthorisationException) {
            throw HttpException::forbidden('Your role may not submit a referral.');
        } catch (PDOException) {
            // The procedure foreign key is the authority: if it refuses a code the
            // form offered, the catalogue changed between render and submit.
            return $this->form($input, [
                'procedure_code' => 'That procedure is no longer offered. Choose another.',
            ], 422);
        }

        Session::flash('notice', 'Referral ' . $referral->reference . ' submitted.');

        return Response::redirect('/referrals/' . rawurlencode($referral->reference));
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $user = $this->gate->authenticate();

        $referral = $this->referrals->findByReference((string) ($params['reference'] ?? ''));

        // A referrer may open their own referral — including a draft, which is
        // theirs alone. Everyone else needs the triage permission. The check is
        // on the record, not on the URL, so guessing a reference gets a 404.
        if (
            $referral === null
            || (!$user->can(Permission::ReferralTriage) && $referral->submittedByUserId !== $user->id)
        ) {
            throw new HttpException(404);
        }

        return $this->detail($referral, [], Session::pullFlash('notice'), 200);
    }

    /**
     * @param array<string, string> $errors
     */
    private function detail(Referral $referral, array $errors, ?string $notice, int $status): Response
    {
        return Response::html($this->view->render('referrals/show', [
            'title' => 'Referral ' . $referral->reference,
            'referral' => $referral,
            'procedure' => $this->procedures->find($referral->procedureCode),
            'decision' => $this->standingDecision($referral),
            'priorityChanges' => $this->priorityChanges($referral),
            'priorities' => Priority::cases(),
            'assessment' => $this->assessments->current($referral->id),
            'assessmentHistory' => $this->assessments->history($referral->id),
            'anticoagulantStatuses' => AnticoagulantStatus::cases(),
            'allergyStatuses' => AllergyStatus::cases(),
            'pregnancyStatuses' => PregnancyStatus::cases(),
            'imagingModalities' => ImagingModality::cases(),
            'guardianVerification' => $this->referrals->guardianVerification($referral->id),
            'consent' => $this->consents->current($referral->id),
            'consentHistory' => $this->consents->history($referral->id),
            'consentParticipants' => ConsentParticipant::cases(),
            'consentOutcomes' => ConsentOutcome::cases(),
            'notice' => $notice,
            'errors' => $errors,
            'currentUser' => $this->gate->authenticate(),
        ]), $status);
    }

    /** @param array<string, string> $params */
    public function reassignPriority(Request $request, array $params): Response
    {
        $this->gate->authorize(Permission::ClinicalApprove);

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $referral = $this->referrals->findByReference((string) ($params['reference'] ?? ''));

        if ($referral === null) {
            throw new HttpException(404);
        }

        try {
            $this->reassign->execute($this->actor(), $referral->id, [
                'clinical_priority' => (string) $request->input('clinical_priority', ''),
                'reason' => (string) $request->input('reason', ''),
            ]);
        } catch (ValidationException $exception) {
            return $this->detail($referral, $exception->errors, null, 422);
        }

        Session::flash('notice', 'Clinical priority changed on ' . $referral->reference . '.');

        return Response::redirect('/referrals/' . rawurlencode($referral->reference));
    }

    /**
     * Record that a member of staff checked the guardian's authority (PAT-013).
     *
     * `referral.triage` because §6.3 assigns the check to reception, a clinician or
     * governance — the people who can see the referral and are accountable for it.
     *
     * @param array<string, string> $params
     */
    public function verifyGuardian(Request $request, array $params): Response
    {
        $this->gate->authorize(Permission::ReferralTriage);
        $this->assertCsrf($request);

        $referral = $this->referrals->findByReference((string) ($params['reference'] ?? ''));

        if ($referral === null) {
            throw new HttpException(404);
        }

        try {
            $this->verifyGuardianCommand->execute(
                $this->actor(),
                $referral->id,
                (string) $request->input('guardian_verification_basis', ''),
            );
        } catch (ValidationException $exception) {
            return $this->detail($referral, $exception->errors, null, 422);
        } catch (ConsentNotPermittedException $exception) {
            return $this->detail($referral, ['guardian' => $exception->getMessage()], null, 422);
        }

        Session::flash('notice', 'Guardian authority verified on ' . $referral->reference . '.');

        return Response::redirect('/referrals/' . rawurlencode($referral->reference));
    }
    /**
     * Record the pre-procedure assessment (§6.5, §6.6).
     *
     * The route and the command both require `clinical.review`: an assessment is a
     * clinician's judgement, and reception seeing that one exists is different from
     * reception being able to write it.
     *
     * @param array<string, string> $params
     */
    public function recordAssessment(Request $request, array $params): Response
    {
        $this->gate->authorize(Permission::ClinicalReview);
        $this->assertCsrf($request);

        $referral = $this->referrals->findByReference((string) ($params['reference'] ?? ''));

        if ($referral === null) {
            throw new HttpException(404);
        }

        try {
            $this->recordAssessmentCommand->execute($this->actor(), $referral->id, $request->all());
        } catch (ValidationException $exception) {
            return $this->detail($referral, $exception->errors, null, 422);
        } catch (AssessmentNotPermittedException $exception) {
            return $this->detail($referral, ['assessment' => $exception->getMessage()], null, 422);
        }

        Session::flash('notice', 'Assessment recorded for ' . $referral->reference . '.');

        return Response::redirect('/referrals/' . rawurlencode($referral->reference));
    }

    /**
     * Record a consent discussion (§18.1).
     *
     * The route carries `clinical.approve` and the command repeats the check: a
     * consent record is a clinician's attestation, and a misconfigured route must
     * not be able to produce one on reception's behalf.
     *
     * @param array<string, string> $params
     */
    public function recordConsent(Request $request, array $params): Response
    {
        $this->gate->authorize(Permission::ClinicalApprove);
        $this->assertCsrf($request);

        $referral = $this->referrals->findByReference((string) ($params['reference'] ?? ''));

        if ($referral === null) {
            throw new HttpException(404);
        }

        try {
            $this->recordConsentCommand->execute($this->actor(), $referral->id, $request->all());
        } catch (ValidationException $exception) {
            return $this->detail($referral, $exception->errors, null, 422);
        } catch (ConsentNotPermittedException $exception) {
            return $this->detail($referral, ['consent' => $exception->getMessage()], null, 422);
        }

        Session::flash('notice', 'Consent recorded for ' . $referral->reference . '.');

        return Response::redirect('/referrals/' . rawurlencode($referral->reference));
    }

    /**
     * Record that an agreement no longer stands.
     *
     * The form carries the record it withdraws, so two open tabs cannot withdraw
     * the wrong one silently.
     *
     * @param array<string, string> $params
     */
    public function withdrawConsent(Request $request, array $params): Response
    {
        $this->gate->authorize(Permission::ClinicalApprove);
        $this->assertCsrf($request);

        $referral = $this->referrals->findByReference((string) ($params['reference'] ?? ''));

        if ($referral === null) {
            throw new HttpException(404);
        }

        try {
            $this->withdrawConsentCommand->execute(
                $this->actor(),
                (int) $request->input('consent_id', '0'),
                (string) $request->input('withdrawal_reason', ''),
            );
        } catch (ValidationException $exception) {
            return $this->detail($referral, $exception->errors, null, 422);
        } catch (ConsentNotPermittedException | ConsentNotFoundException $exception) {
            return $this->detail($referral, ['consent' => $exception->getMessage()], null, 422);
        }

        Session::flash('notice', 'Consent withdrawn on ' . $referral->reference . '.');

        return Response::redirect('/referrals/' . rawurlencode($referral->reference));
    }

    private function actor(): Actor
    {
        return new Actor($this->gate->authenticate()->id, $this->gate->authenticate()->role);
    }

    private function localTime(string $utc): string
    {
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));

        if ($at === false) {
            return $utc;
        }

        return $at->setTimezone(new \DateTimeZone($this->timezone))->format('D j M Y, H:i');
    }

    /**
     * The recorded changes of clinical priority, in the clinic's time.
     *
     * @return list<array{change: ClinicalPriorityChange, changedBy: string, changedAt: string}>
     */
    private function priorityChanges(Referral $referral): array
    {
        $rows = [];

        foreach ($this->referrals->priorityChangeHistory($referral->id) as $entry) {
            $rows[] = [
                'change' => $entry['change'],
                'changedBy' => $entry['changedBy'],
                'changedAt' => $this->localTime($entry['change']->changedAtUtc),
            ];
        }

        return $rows;
    }

    /**
     * The clinical decision the desk is acting on, if there is one.
     *
     * The desk books what a clinician approved, so it has to be able to read the
     * approval: who signed it, when, and with what technique and duration. A page
     * that only shows "clinically approved" asks reception to trust a status.
     *
     * @return array{
     *     type: string,
     *     rationale: string,
     *     reviewer: string,
     *     decidedAt: string,
     *     technique: ?string,
     *     modality: ?string,
     *     sedation: ?string,
     *     minutes: ?int
     * }|null
     */
    private function standingDecision(Referral $referral): ?array
    {
        foreach ($this->referrals->decisionHistory($referral->id) as $record) {
            if ($record->isCurrent()) {
                return $this->decisionRow($record);
            }
        }

        return null;
    }

    /**
     * @return array{
     *     type: string,
     *     rationale: string,
     *     reviewer: string,
     *     decidedAt: string,
     *     technique: ?string,
     *     modality: ?string,
     *     sedation: ?string,
     *     minutes: ?int
     * }
     */
    private function decisionRow(ClinicalDecisionRecord $record): array
    {
        $decision = $record->decision;
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $decision->decidedAtUtc, new DateTimeZone('UTC'));

        return [
            'type' => $decision->type->label(),
            'rationale' => $decision->rationale,
            'reviewer' => $record->reviewerName === '' ? 'Unknown reviewer' : $record->reviewerName,
            'decidedAt' => $at === false
                ? $decision->decidedAtUtc
                : $at->setTimezone(new DateTimeZone($this->timezone))->format('D j M Y, H:i')
                    . ' (' . $this->timezone . ')',
            'technique' => $decision->technique,
            'modality' => $decision->modality,
            'sedation' => $decision->sedation,
            'minutes' => $decision->estimatedDurationMinutes,
        ];
    }

    /**
     * The desk's three moves, as a fixed map rather than a target taken from the
     * request. A generic "set status" endpoint would let a hand-crafted POST reach
     * `clinically_approved`, which is the one thing this route must not allow.
     */
    private const ADVANCE = [
        'receive' => ReferralStatus::Received,
        'triage' => ReferralStatus::AdministrativeReview,
        'request-information' => ReferralStatus::AwaitingInformation,
        'send-to-review' => ReferralStatus::ClinicalReview,
    ];

    /** @param array<string, string> $params */
    public function advance(Request $request, array $params): Response
    {
        $this->gate->authorize(Permission::ReferralTriage);
        $this->assertCsrf($request);

        $user = $this->gate->authenticate();
        $reference = (string) ($params['reference'] ?? '');
        $target = self::ADVANCE[(string) $request->input('action', '')] ?? null;

        if ($target === null) {
            throw new HttpException(422, 'Unknown action.');
        }

        $referral = $this->referrals->findByReference($reference);

        if ($referral === null) {
            throw new HttpException(404);
        }

        $reason = trim((string) $request->input('reason', ''));

        // The state machine does not require a reason for this transition, but a
        // request for information with no statement of what is missing is a
        // message the referrer cannot act on.
        if ($target === ReferralStatus::AwaitingInformation && $reason === '') {
            return $this->detail($referral, ['reason' => 'Say what is missing.'], null, 422);
        }

        try {
            $updated = $this->referrals->transition(
                $referral,
                $target,
                new Actor($user->id, $user->role),
                $reason === '' ? null : mb_substr($reason, 0, 480),
                gmdate('Y-m-d H:i:s'),
            );
        } catch (ReferralTransitionException $exception) {
            return $this->detail($referral, ['action' => $exception->getMessage()], null, 422);
        }

        Session::flash(
            'notice',
            'Referral ' . $updated->reference . ' is now ' . mb_strtolower($updated->status->label()) . '.',
        );

        return Response::redirect('/referrals/' . rawurlencode($reference));
    }

    /**
     * Record what the referrer sent back.
     *
     * The desk records it rather than the referrer: a status transition is an
     * authority the role table gives staff and administrators, not physicians.
     * A referrer-facing reply surface arrives with the portal (BL-E1), where the
     * actor is the referrer's own account.
     *
     * @param array<string, string> $params
     */
    public function reply(Request $request, array $params): Response
    {
        $this->gate->authorize(Permission::ReferralTriage);
        $this->assertCsrf($request);

        $user = $this->gate->authenticate();
        $reference = (string) ($params['reference'] ?? '');
        $referral = $this->referrals->findByReference($reference);

        if ($referral === null) {
            throw new HttpException(404);
        }

        $reply = trim((string) $request->input('reply', ''));

        if (mb_strlen($reply) < 5) {
            return $this->detail($referral, ['reply' => 'Record what was sent back.'], null, 422);
        }

        try {
            $updated = $this->referrals->transition(
                $referral,
                ReferralStatus::AdministrativeReview,
                new Actor($user->id, $user->role),
                mb_substr($reply, 0, 480),
                gmdate('Y-m-d H:i:s'),
            );
        } catch (ReferralTransitionException $exception) {
            return $this->detail($referral, ['reply' => $exception->getMessage()], null, 422);
        }

        Session::flash('notice', 'Reply recorded; ' . $updated->reference . ' is back in administrative review.');

        return Response::redirect('/referrals/' . rawurlencode($reference));
    }

    /**
     * Release a clinically approved referral to the booking desk.
     *
     * The status change is not made here: `ApproveForScheduling` reads the
     * standing clinical decision before it moves anything, so a referral whose
     * approval was superseded cannot be released by a URL (WF-02). Without a
     * route, an approved referral had nowhere to go and the desk had to retype a
     * reference into the scheduling form — a working system with no way through it.
     *
     * @param array<string, string> $params
     */
    public function release(Request $request, array $params): Response
    {
        $this->assertCsrf($request);

        $user = $this->gate->authenticate();
        $reference = (string) ($params['reference'] ?? '');
        $referral = $this->referrals->findByReference($reference);

        if ($referral === null) {
            throw new HttpException(404);
        }

        try {
            $released = $this->release->execute(
                new Actor($user->id, $user->role),
                $referral->id,
                gmdate('Y-m-d H:i:s'),
            );
        } catch (AuthorisationException) {
            throw HttpException::forbidden('Your role may not release a referral for scheduling.');
        } catch (ReferralTransitionException $exception) {
            return $this->detail($referral, ['release' => $exception->getMessage()], null, 422);
        } catch (ReferralNotFoundException) {
            throw new HttpException(404);
        }

        Session::flash(
            'notice',
            'Referral ' . $released->reference . ' is released; it is now in the scheduling queue.',
        );

        return Response::redirect('/referrals/' . rawurlencode($released->reference));
    }

    public function saveDraft(Request $request): Response
    {
        $this->gate->authorize(Permission::ReferralSubmit);
        $this->assertCsrf($request);

        $user = $this->gate->authenticate();
        $input = $this->input($request);

        try {
            $draft = $this->drafts->execute(new Actor($user->id, $user->role), $input);
        } catch (ValidationException $exception) {
            return $this->form($input, $exception->errors, 422);
        }

        Session::flash('notice', 'Draft saved. Submit it when the details are complete.');

        return Response::redirect('/referrals/' . rawurlencode($draft->reference));
    }

    /** @param array<string, string> $params */
    public function submit(Request $request, array $params): Response
    {
        $user = $this->gate->authenticate();
        $this->assertCsrf($request);

        $reference = (string) ($params['reference'] ?? '');

        try {
            $referral = $this->submitDraft->execute(new Actor($user->id, $user->role), $reference);
        } catch (ValidationException $exception) {
            $draft = $this->referrals->findByReference($reference);

            if ($draft === null) {
                throw new HttpException(404);
            }

            // Re-render the draft with the missing pieces marked, rather than
            // flashing a message that disappears on the next page load.
            return $this->detail($draft, $exception->errors, null, 422);
        } catch (AuthorisationException) {
            throw HttpException::forbidden('That draft belongs to somebody else.');
        } catch (ReferralNotFoundException | ReferralTransitionException $exception) {
            throw new HttpException(404, $exception->getMessage());
        }

        Session::flash('notice', 'Referral ' . $referral->reference . ' submitted.');

        return Response::redirect('/referrals/' . rawurlencode($referral->reference));
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function form(array $values, array $errors, int $status): Response
    {
        /** @var list<Procedure> $catalogue */
        $catalogue = $this->procedures->elective();

        return Response::html($this->view->render('referrals/new', [
            'title' => 'New referral',
            'values' => $values,
            'errors' => $errors,
            'procedures' => $catalogue,
            'urgencies' => self::urgencies(),
            'idempotencyKey' => bin2hex(random_bytes(16)),
            'currentUser' => $this->gate->authenticate(),
        ]), $status);
    }

    /** @return array<string, string> */
    private function input(Request $request): array
    {
        $values = [];

        foreach (self::FIELDS as $field) {
            $value = $request->input($field, '');

            $values[$field] = is_string($value) ? trim($value) : '';
        }

        return $values;
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->input('idempotency_key', '');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function assertCsrf(Request $request): void
    {
        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }
    }
}
