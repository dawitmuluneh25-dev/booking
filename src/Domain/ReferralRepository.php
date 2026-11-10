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
    /**
     * Persist the referral, its opening history row and any notifications in one
     * transaction (WF-14): a message that cannot be enqueued must not leave a
     * referral behind, and a message that cannot be delivered must not undo one.
     *
     * @param list<OutboundMessage> $messages
     */
    public function create(
        Referral $referral,
        Actor $actor,
        string $occurredAtUtc,
        array $messages = [],
    ): Referral;

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

    /**
     * Referrals waiting to be booked, oldest first.
     *
     * The wizard offers only these: scheduling something that has not been
     * released clinically is the failure WF-02 exists to prevent, so the query
     * asks the status column for exactly one value rather than filtering in the
     * template.
     *
     * @return list<Referral>
     */
    public function approvedForScheduling(int $limit = 50): array;

    /**
     * Referrals a person can act on at the front desk: submitted, received, in
     * administrative review, or waiting on the referrer. Oldest first, because the
     * queue is worked from the top.
     *
     * draft is deliberately absent: a draft belongs to its author and has not
     * been submitted, so it is not the clinic's work yet.
     *
     * @return list<Referral>
     */
    public function queued(int $limit = 100): array;

    /**
     * The decision that currently stands: the row nothing supersedes.
     *
     * Newest-by-timestamp would be wrong after a correction, because the corrected
     * row is not necessarily the later one.
     */
    public function currentDecision(int $referralId): ?ClinicalDecision;

    /**
     * Append a decision. The row is never updated afterwards; the database allows
     * each decision to be superseded once, which keeps the chain linear.
     */
    public function recordDecision(ClinicalDecision $decision): ClinicalDecision;
}
