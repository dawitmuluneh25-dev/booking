<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Persistence boundary for referrals.
 *
 * Every method that changes a row must write the matching history row in the same
 * transaction: the history table is the audit trail for the clinical pathway
 * (WF-09), so a status change without one is a defect rather than a shortcut.
 *
 * Implementations must use prepared statements and must not accept SQL fragments.
 */
interface ReferralRepository
{
    /**
     * Store a new referral and its opening history row, returning it with the
     * assigned id. `$occurredAtUtc` is passed in rather than read from the clock
     * inside the repository so a test can pin it.
     */
    public function create(Referral $referral, Actor $actor, string $occurredAtUtc): Referral;

    public function findByReference(string $reference): ?Referral;

    public function findById(int $id): ?Referral;

    /**
     * Apply a transition only if the stored version is still the one read.
     *
     * @throws ReferralTransitionException when the referral has moved on since it
     *                                    was read, or the transition is not allowed
     */
    public function transition(
        Referral $referral,
        ReferralStatus $target,
        Actor $actor,
        ?string $reason,
        string $occurredAtUtc,
    ): Referral;
}
