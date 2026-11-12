<?php

declare(strict_types=1);

namespace App\Domain;

use App\Validation\Validator;

/**
 * Record the coordination decision on an urgent case (§10.2 `INT-016`).
 *
 * The decision and the move it releases are written in one unit of work, so there
 * is no window in which a case reads `accepted_for_coordination` with no decision
 * behind it — the state a reader would later treat as evidence that a clinician
 * accepted the child, on no attributable record at all.
 *
 * Two things this command deliberately refuses:
 *
 *  - a decision whose choice cannot legally move the case from where it is, which
 *    stops `transfer` being recorded against a case nobody has accepted;
 *  - a decision on a closed case, because a closed case has no live question left
 *    to answer and a late row would read as a correction that never happened.
 *
 * The authority is checked three times, as it is for the elective decision: the
 * actor must hold `urgent.coordinate`, the transition table must name the actor's
 * role as able to make the move, and the repository repeats the second check
 * before it writes.
 */
final class RecordUrgentDecision
{
    private const MIN_RATIONALE = 10;
    private const MAX_RATIONALE = 4000;

    public function __construct(
        private readonly UrgentCaseRepository $repository,
        private readonly Validator $validator,
        private readonly Transaction $transaction,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AuthorisationException      the actor may not coordinate
     * @throws UrgentCaseNotFoundException the case does not exist
     * @throws ValidationException         the decision is incomplete
     * @throws UrgentTransitionException   the choice cannot move this case
     */
    public function execute(Actor $actor, int $caseId, array $input, ?string $nowUtc = null): UrgentCase
    {
        if (!$actor->can(Permission::UrgentCoordinate)) {
            throw new AuthorisationException($actor->role, Permission::UrgentCoordinate);
        }

        $case = $this->repository->findById($caseId);

        if ($case === null) {
            throw new UrgentCaseNotFoundException($caseId);
        }

        $data = [
            'decision_type' => $this->text($input, 'decision_type'),
            'rationale' => $this->text($input, 'rationale'),
        ];

        $errors = $this->validator->validate($data, [
            'rationale' => 'required|min:' . self::MIN_RATIONALE . '|max:' . self::MAX_RATIONALE,
        ]);

        $type = UrgentDecisionType::tryFrom($data['decision_type']);

        if ($type === null) {
            $errors['decision_type'] = 'Choose one of the recorded decisions: '
                . implode(', ', UrgentDecisionType::values()) . '.';
        } elseif (!$type->canBeRecordedFrom($case->urgency)) {
            // The refusal names the state rather than the rule, because the person
            // reading it is trying to work out what to do next, not audit the code.
            $errors['decision_type'] = $case->urgency->isTerminal()
                ? 'This case is closed, so it has no decision left to record.'
                : 'That decision does not fit this case at "' . $case->urgency->label()
                    . '". A case is reviewed, then accepted, then completed or transferred.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        /** @var UrgentDecisionType $type A null would have been an error above. */
        $target = $type->resultingStatus();

        // The repository enforces the role for a choice that moves the case. A
        // choice that releases no state has no transition to check, so its
        // authority is checked here — otherwise "defer pending review" would be
        // recordable by anyone who may coordinate a case.
        if ($target === null && !in_array($actor->role, $type->releasingRoles(), true)) {
            throw new AuthorisationException($actor->role, Permission::UrgentCoordinate);
        }
        $decidedAtUtc = $nowUtc ?? gmdate('Y-m-d H:i:s');
        $current = $this->repository->currentUrgentDecision($case->id);

        $decision = new UrgentDecision(
            id: 0,
            urgentCaseId: $case->id,
            type: $type,
            rationale: $data['rationale'],
            decidedByUserId: $actor->userId,
            decidedAtUtc: $decidedAtUtc,
            resultingStatus: $target?->value,
            supersedesId: $current?->id,
        );

        return $this->transaction->run(
            function () use ($case, $decision, $target, $actor, $data, $decidedAtUtc): UrgentCase {
                $this->repository->recordUrgentDecision($decision);

                if ($target === null) {
                    // A deferral or an `other` leaves the case exactly where it is.
                    // Moving it anyway would turn a recorded wait into an answer.
                    return $case;
                }

                return $this->repository->transitionUrgency(
                    $case,
                    $target,
                    $actor,
                    $data['rationale'],
                    $decidedAtUtc,
                );
            },
        );
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}
