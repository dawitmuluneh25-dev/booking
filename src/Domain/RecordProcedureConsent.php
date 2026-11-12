<?php

declare(strict_types=1);

namespace App\Domain;

use App\Validation\Validator;

/**
 * Record a consent discussion (§18.1).
 *
 * The command exists because consent is not a status. A referral can be approved
 * and still have nobody's agreement recorded, and a procedure cannot honestly
 * proceed on the strength of a referral's state alone (§18.1, AC-005).
 *
 * Four refusals carry the clinical meaning:
 *
 *   1. Only a holder of `clinical.approve` may record consent. The dialogue is a
 *      clinician's act; reception may see that it happened, not attest to it.
 *   2. The referral must already be clinically approved. Recording agreement before
 *      any decision would let a submission acquire consent to a procedure nobody
 *      has agreed to perform.
 *   3. An agreement on behalf of somebody else requires verified authority
 *      (§18.2). The counterpart is deliberately permissive: a decline or an
 *      inability to take part is recorded without it, because refusing care is not
 *      an act that needs a representative's authorisation.
 *   4. Nothing here produces an emergency override. A patient who cannot take part
 *      is recorded as `unable_to_consent` with the reason and remains a person's
 *      problem to solve — §18.3 refuses a generic bypass, and a boolean would be
 *      exactly that.
 *
 * The record is append-only. Recording again supersedes the previous row rather
 * than editing it, so what was agreed in March survives what was agreed in April.
 */
final class RecordProcedureConsent
{
    /**
     * States in which a consent discussion can legitimately be recorded: after the
     * clinical decision, before the procedure.
     *
     * A draft, a submitted referral or one still in review is refused — there is no
     * approved technique to discuss yet, and a consent recorded against a referral
     * that is later declined would describe a conversation about nothing.
     *
     * @var list<ReferralStatus>
     */
    private const CONSENTABLE = [
        ReferralStatus::ClinicallyApproved,
        ReferralStatus::ApprovedForScheduling,
        ReferralStatus::ConvertedToAppointment,
    ];

    public function __construct(
        private readonly ConsentRepository $consents,
        private readonly ReferralRepository $referrals,
        private readonly Validator $validator,
        private readonly Transaction $transaction,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AuthorisationException       the actor is not a clinician
     * @throws ReferralNotFoundException    the referral does not exist
     * @throws ValidationException          the record is incomplete
     * @throws ConsentNotPermittedException the referral is not at a point where
     *                                      consent can be recorded
     */
    public function execute(Actor $actor, int $referralId, array $input, ?string $nowUtc = null): ProcedureConsent
    {
        if (!$actor->can(Permission::ClinicalApprove)) {
            throw new AuthorisationException($actor->role, Permission::ClinicalApprove);
        }

        $referral = $this->referrals->findById($referralId);

        if ($referral === null) {
            throw new ReferralNotFoundException('#' . $referralId);
        }

        if (!in_array($referral->status, self::CONSENTABLE, true)) {
            throw new ConsentNotPermittedException(
                $referral->status,
                'Consent is recorded after the clinical decision and before the procedure.',
            );
        }

        $data = [
            'participant_relationship' => $this->text($input, 'participant_relationship'),
            'participant_name' => $this->text($input, 'participant_name'),
            'outcome' => $this->text($input, 'outcome'),
            'information_version' => $this->text($input, 'information_version'),
            'information_language' => $this->text($input, 'information_language'),
            'discussion_record' => $this->text($input, 'discussion_record'),
            'attestation_reference' => $this->text($input, 'attestation_reference'),
            'target_site' => $this->text($input, 'target_site'),
            'capacity_note' => $this->text($input, 'capacity_note'),
            'questions_recorded' => $this->text($input, 'questions_recorded'),
            'interpreter_language' => $this->text($input, 'interpreter_language'),
        ];

        $errors = $this->validator->validate($data, [
            'participant_relationship' => 'required|in:' . $this->relationshipList(),
            'participant_name' => 'required|max:120',
            'outcome' => 'required|in:' . $this->outcomeList(),
            'information_version' => 'required|max:60',
            'information_language' => 'required|max:16',
            'discussion_record' => 'required|min:30|max:8000',
            'attestation_reference' => 'required|max:200',
            'target_site' => 'max:120',
            'capacity_note' => 'max:500',
            'questions_recorded' => 'max:4000',
            'interpreter_language' => 'max:16',
        ]);

        $participant = ConsentParticipant::tryFrom($data['participant_relationship']);
        $outcome = ConsentOutcome::tryFrom($data['outcome']);

        $authorityVerified = $this->flag($input, 'authority_verified');
        $capacityAssessed = $this->flag($input, 'capacity_assessed');
        $interpreterInvolved = $this->flag($input, 'interpreter_involved');

        if ($interpreterInvolved && $data['interpreter_language'] === '') {
            $errors['interpreter_language'] = 'Say which language the interpreter worked in.';
        }

        if ($participant === ConsentParticipant::Self && $authorityVerified) {
            $errors['authority_verified'] = 'The patient takes part on their own account; there is '
                . 'no representative authority to verify.';
        }

        if (
            $outcome === ConsentOutcome::Agreed
            && $participant !== null
            && $participant->needsVerifiedAuthority()
            && !$authorityVerified
        ) {
            $errors['authority_verified'] = 'Verify the representative’s authority before recording '
                . 'an agreement on the patient’s behalf.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // Both are non-null here: the `in:` rules above refused anything the enums
        // do not name, so these fallbacks are for the analyser, not for a user.
        $participant ??= ConsentParticipant::Other;
        $outcome ??= ConsentOutcome::UnableToConsent;

        $recordedAtUtc = $nowUtc ?? gmdate('Y-m-d H:i:s');

        // Read before writing: a correction names the row it replaces. Two people
        // recording at once would both name the same predecessor, and the unique key
        // on `supersedes_consent_id` refuses the second rather than forking the chain.
        $current = $this->consents->current($referral->id);

        $consent = new ProcedureConsent(
            id: 0,
            referralId: $referral->id,
            participant: $participant,
            participantName: $data['participant_name'],
            authorityVerified: $authorityVerified,
            // The verifier is the person recording: they are the one making the
            // claim, and the record has to name them.
            authorityVerifiedByUserId: $authorityVerified ? $actor->userId : null,
            authorityVerifiedAtUtc: $authorityVerified ? $recordedAtUtc : null,
            capacityAssessed: $capacityAssessed,
            capacityNote: $this->nullable($data['capacity_note']),
            targetSite: $this->nullable($data['target_site']),
            informationVersion: $data['information_version'],
            informationLanguage: $data['information_language'],
            discussionRecord: $data['discussion_record'],
            questionsRecorded: $this->nullable($data['questions_recorded']),
            interpreterInvolved: $interpreterInvolved,
            interpreterLanguage: $interpreterInvolved ? $this->nullable($data['interpreter_language']) : null,
            outcome: $outcome,
            attestationReference: $data['attestation_reference'],
            responsiblePractitionerUserId: $actor->userId,
            recordedByUserId: $actor->userId,
            consentedAtUtc: $recordedAtUtc,
            supersedesId: $current?->id,
        );

        return $this->transaction->run(fn (): ProcedureConsent => $this->consents->record($consent));
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * A checkbox that is absent and a checkbox that is unticked are the same fact,
     * so the default is false and nothing unrecognised counts as a yes.
     *
     * @param array<string, mixed> $input
     */
    private function flag(array $input, string $key): bool
    {
        $value = $input[$key] ?? null;

        return $value === true || $value === 1 || $value === '1' || $value === 'on';
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function relationshipList(): string
    {
        return implode(',', array_map(
            static fn (ConsentParticipant $case): string => $case->value,
            ConsentParticipant::cases(),
        ));
    }

    private function outcomeList(): string
    {
        return implode(',', array_map(
            static fn (ConsentOutcome $case): string => $case->value,
            ConsentOutcome::cases(),
        ));
    }
}
