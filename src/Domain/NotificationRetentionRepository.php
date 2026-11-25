<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The persistence side of the notification class (D-02: 365 days).
 *
 * Kept separate from `RetentionRepository` (bookings) and `ReferralRetentionRepository` (referrals and
 * documents) for the same reason those two are separate: the eligibility rule and the thing being
 * retired are different. A booking is redacted because the appointment is over; a notification is
 * retired because a year has passed, whatever the underlying record is still doing.
 *
 * The contract deliberately has no `delete`. Retiring a notification means removing what identifies a
 * person — the recipient and the payload — while keeping the row that proves a message was queued,
 * attempted and accepted. Deleting the row would destroy delivery accounting that the outbox exists
 * to provide, and would break the unique `dedupe_key` that makes dispatch idempotent.
 */
interface NotificationRetentionRepository
{
    /** Outbox rows past the cutoff that still carry a recipient or a payload. */
    public function eligibleCount(string $cutoffUtc): int;

    /**
     * Retire one batch: overwrite the recipient, the payload and the last provider error, stamp
     * `redacted_at`, and return how many rows were changed.
     */
    public function redactBefore(string $cutoffUtc, int $limit): int;

    /** Record the sweep in `retention_runs`, alongside the booking, referral and document counts. */
    public function recordRun(string $cutoffDate, int $redacted, string $actor): void;
}
