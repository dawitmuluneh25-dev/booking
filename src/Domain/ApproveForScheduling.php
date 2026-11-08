<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Release a clinically approved referral to the scheduling queue.
 *
 * This is where WF-02 is enforced rather than assumed: the referral's status is
 * not evidence enough, because a status is one row that can be wrong. The command
 * reads the standing clinical decision and refuses unless it is an approval, so a
 * referral that says "clinically approved" but carries a deferral, or carries no
 * decision at all, cannot reach the booking desk.
 *
 * The actor may be administrative — releasing work is not deciding it — but the
 * decision being released must exist and must be the one that still stands.
 */
final class ApproveForScheduling
{
    public function __construct(private readonly ReferralRepository $repository)
    {
    }

    /**
     * @throws AuthorisationException      the actor may not schedule
     * @throws ReferralNotFoundException   the referral does not exist
     * @throws ReferralTransitionException the referral is not clinically approved,
     *                                     or its standing decision is not an approval
     */
    public function execute(Actor $actor, int $referralId, ?string $nowUtc = null): Referral
    {
        if (!$actor->can(Permission::ScheduleManage)) {
            throw new AuthorisationException($actor->role, Permission::ScheduleManage);
        }

        $referral = $this->repository->findById($referralId);

        if ($referral === null) {
            throw new ReferralNotFoundException('#' . $referralId);
        }

        if ($referral->status !== ReferralStatus::ClinicallyApproved) {
            throw new ReferralTransitionException($referral->status, ReferralStatus::ApprovedForScheduling);
        }

        $decision = $this->repository->currentDecision($referral->id);

        if ($decision === null || $decision->type !== ClinicalDecisionType::Approved) {
            // The status says approved; the decision says otherwise, or says nothing.
            // The decision wins, because it is the record of clinical judgement.
            throw new ReferralTransitionException(
                $referral->status,
                ReferralStatus::ApprovedForScheduling,
                'A standing clinical approval is required before this referral can be scheduled.',
            );
        }

        return $this->repository->transition(
            $referral,
            ReferralStatus::ApprovedForScheduling,
            $actor,
            null,
            $nowUtc ?? gmdate('Y-m-d H:i:s'),
        );
    }
}
