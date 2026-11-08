<?php

declare(strict_types=1);

namespace App\Domain;

use App\Validation\Validator;

/**
 * Record a radiologist's clinical decision on an elective referral.
 *
 * This is the command the prototype did not have, and the reason is worth stating:
 * in the redesign an administrator could move a referral to "approved" because
 * approval was just a status the dashboard wrote. Here the authority is checked
 * three times —
 *
 *   1. the actor must hold clinical.approve,
 *   2. the current state must list the actor's role as able to perform the
 *      transition to the resulting state,
 *   3. the repository repeats (2) before it writes.
 *
 * Only the radiologist role satisfies all three, so a misconfigured route cannot
 * produce a clinical decision on behalf of reception (WF-03, WF-11).
 *
 * A decision and the referral's new state are written in one unit of work: there
 * is no window in which a decision exists on a referral that still reads
 * "clinical review".
 */
final class RecordClinicalDecision
{
    public function __construct(
        private readonly ReferralRepository $repository,
        private readonly Validator $validator,
        private readonly Transaction $transaction,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AuthorisationException      the actor is not a clinician
     * @throws ReferralNotFoundException   the referral does not exist
     * @throws ValidationException         the decision is incomplete
     * @throws ReferralTransitionException the referral is not awaiting a decision
     */
    public function execute(Actor $actor, int $referralId, array $input, ?string $nowUtc = null): Referral
    {
        if (!$actor->can(Permission::ClinicalApprove)) {
            throw new AuthorisationException($actor->role, Permission::ClinicalApprove);
        }

        $referral = $this->repository->findById($referralId);

        if ($referral === null) {
            throw new ReferralNotFoundException('#' . $referralId);
        }

        $type = $this->type($input);
        $target = $type->resultingStatus();

        if (!$referral->status->isClinicalReviewState()) {
            // Refuse before writing anything: a decision recorded against a referral
            // in triage would silently skip review.
            throw new ReferralTransitionException($referral->status, $target);
        }

        $data = [
            'rationale' => $this->text($input, 'rationale'),
            'technique' => $this->text($input, 'approved_technique'),
            'modality' => $this->text($input, 'modality'),
            'sedation' => $this->text($input, 'sedation'),
            'duration' => $this->text($input, 'estimated_duration_minutes'),
            'preprocedure' => $this->text($input, 'preprocedure_conditions'),
            'redirect' => $this->text($input, 'redirect_destination'),
            'information' => $this->text($input, 'information_requested'),
        ];

        $errors = $this->validator->validate($data, [
            'rationale' => 'required|min:10|max:4000',
            'technique' => 'max:120',
            'modality' => 'max:60',
            'sedation' => 'max:60',
            'duration' => 'max:4',
            'preprocedure' => 'max:2000',
            'redirect' => 'max:160',
            'information' => 'max:2000',
        ]);

        if ($data['duration'] !== '' && preg_match('/^[1-9][0-9]{0,2}$/', $data['duration']) !== 1) {
            $errors['duration'] = 'Estimated duration must be a whole number of minutes.';
        }

        if ($type === ClinicalDecisionType::Redirected && $data['redirect'] === '') {
            $errors['redirect'] = 'Say where the patient is being redirected to.';
        }

        if ($type === ClinicalDecisionType::MoreInformation && $data['information'] === '') {
            $errors['information'] = 'State what information is needed.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $decidedAtUtc = $nowUtc ?? gmdate('Y-m-d H:i:s');
        $current = $this->repository->currentDecision($referral->id);

        $decision = new ClinicalDecision(
            id: 0,
            referralId: $referral->id,
            type: $type,
            rationale: $data['rationale'],
            technique: $this->nullable($data['technique']),
            modality: $this->nullable($data['modality']),
            sedation: $this->nullable($data['sedation']),
            estimatedDurationMinutes: $data['duration'] === '' ? null : (int) $data['duration'],
            preprocedureConditions: $this->nullable($data['preprocedure']),
            redirectDestination: $this->nullable($data['redirect']),
            informationRequested: $this->nullable($data['information']),
            decidedByUserId: $actor->userId,
            decidedAtUtc: $decidedAtUtc,
            supersedesId: $current?->id,
        );

        return $this->transaction->run(
            function () use ($referral, $decision, $target, $actor, $data, $decidedAtUtc): Referral {
                $this->repository->recordDecision($decision);

                // The rationale becomes the history reason where the transition
                // demands one, so the audit trail carries the clinician's words and
                // not just the fact of the change.
                $reason = $referral->status->requiresReason($target) ? $data['rationale'] : null;

                return $this->repository->transition($referral, $target, $actor, $reason, $decidedAtUtc);
            },
        );
    }

    /** @param array<string, mixed> $input */
    private function type(array $input): ClinicalDecisionType
    {
        $value = $this->text($input, 'decision_type');
        $type = ClinicalDecisionType::tryFrom($value);

        if ($type === null) {
            throw new ValidationException([
                'decision_type' => 'Choose a clinical decision: '
                    . implode(', ', array_map(
                        static fn (ClinicalDecisionType $case): string => $case->value,
                        ClinicalDecisionType::cases(),
                    )) . '.',
            ]);
        }

        return $type;
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
