<?php

declare(strict_types=1);

namespace App\Domain;

use App\Validation\Validator;

/**
 * Change a clinical priority that has already been assigned (§13.1).
 *
 * The other half of "assign/change". Assignment happens once, with the decision
 * that releases the referral; this is what a clinician uses when the answer moves —
 * an expedited case that turns out to need the next list, or a routine one whose
 * imaging changes the picture.
 *
 * Four refusals, each deliberate:
 *
 *  - a referral with no category has nothing to change, because assignment belongs
 *    to the decision and reassigning from nothing would create a priority with no
 *    decision behind it (AC-010);
 *  - a same-to-same change is refused rather than stored, so the table stays a
 *    record of moves;
 *  - "urgent direct coordination" is refused, because its workflow effect is that
 *    the queue is the wrong route. A referral sitting in the elective queue cannot
 *    acquire that category without leaving it, and leaving it is a redirect;
 *  - a referral whose appointment already exists is refused, because escalating it
 *    is then a scheduling decision and this command cannot rebook anything. Saying
 *    "expedited" on a booked case would promise an effect nothing here delivers.
 */
final class ReassignClinicalPriority
{
    private const MIN_REASON = 10;
    private const MAX_REASON = 4000;

    /** States in which the standing category is still the thing that orders the work. */
    private const REASSIGNABLE = [
        ReferralStatus::ClinicallyApproved,
        ReferralStatus::ApprovedForScheduling,
    ];

    public function __construct(
        private readonly ReferralRepository $repository,
        private readonly Validator $validator,
        private readonly Transaction $transaction,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AuthorisationException    the actor is not a clinician
     * @throws ReferralNotFoundException the referral does not exist
     * @throws ValidationException       the change is incomplete or refused
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

        $data = [
            'priority' => $this->text($input, 'clinical_priority'),
            'reason' => $this->text($input, 'reason'),
        ];

        $errors = $this->validator->validate($data, [
            'reason' => 'required|min:' . self::MIN_REASON . '|max:' . self::MAX_REASON,
        ]);

        $priority = $data['priority'] === '' ? null : Priority::tryFrom($data['priority']);
        $refusal = $this->refusal($referral, $priority);

        if ($refusal !== null) {
            // One message, and it is the first thing that stops the change being
            // meaningful. Collecting four at once would tell a clinician to pick a
            // different category when the real answer is that the referral is not
            // in a state where a category can move.
            $errors['clinical_priority'] = $refusal;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        /** @var Priority $priority A null would have been a refusal above. */
        $changedAtUtc = $nowUtc ?? gmdate('Y-m-d H:i:s');

        $change = new ClinicalPriorityChange(
            id: 0,
            referralId: $referral->id,
            fromPriority: $referral->clinicalPriority,
            toPriority: $priority->value,
            reason: $data['reason'],
            changedByUserId: $actor->userId,
            changedAtUtc: $changedAtUtc,
        );

        return $this->transaction->run(function () use ($referral, $change, $priority): Referral {
            // The change row is written first so a failure leaves the standing value
            // untouched rather than moving the queue with no record of the move.
            $this->repository->recordPriorityChange($change);
            $this->repository->assignClinicalPriority($referral->id, $priority->value);

            return $referral->withClinicalPriority($priority->value);
        });
    }

    /**
     * Why this change cannot be made, or null when it can.
     *
     * Ordered by what the reader most needs to know: whether there is anything to
     * change, then whether the referral is in a state where a category still orders
     * its work, then whether the value itself says something this command cannot do.
     */
    private function refusal(Referral $referral, ?Priority $priority): ?string
    {
        if ($referral->clinicalPriority === null) {
            return 'This referral has no clinical priority yet. It is assigned with the decision '
                . 'that releases the referral into the queue, so there is nothing to change.';
        }

        if (!in_array($referral->status, self::REASSIGNABLE, true)) {
            return $referral->status === ReferralStatus::ConvertedToAppointment
                ? 'An appointment exists for this referral, so changing its priority is a '
                    . 'scheduling decision rather than a queue ordering one.'
                : 'A clinical priority can be changed while a referral is approved for scheduling, '
                    . 'and this one is at "' . $referral->status->label() . '".';
        }

        if ($priority === null) {
            return 'Choose one of the approved clinical categories: '
                . implode(', ', array_map(
                    static fn (Priority $case): string => $case->label(),
                    Priority::cases(),
                )) . '.';
        }

        if ($priority->leavesTheOrdinaryQueue()) {
            return 'Urgent direct coordination is not a change of queue position — it says the '
                . 'queue is the wrong route. Redirect the referral to the receiving team instead.';
        }

        if ($priority->value === $referral->clinicalPriority) {
            return 'That is already the standing category, so there is nothing to change.';
        }

        return null;
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}
