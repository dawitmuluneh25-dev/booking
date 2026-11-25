<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\NotificationRetentionRepository;

/**
 * Counts down as it redacts, so batching can be exercised without a database.
 *
 * Mirrors `InMemoryRetentionRepository` (bookings) and `InMemoryReferralRetentionRepository`, including
 * the recorded runs: a test that only asserted the redaction count would pass on a service that forgot
 * to write the run row, and the run row is what an auditor reads.
 */
final class InMemoryNotificationRetentionRepository implements NotificationRetentionRepository
{
    /** @var list<array{redacted: int, actor: string, cutoff: string}> */
    public array $runs = [];

    public function __construct(private int $remaining)
    {
    }

    public function eligibleCount(string $cutoffUtc): int
    {
        return $this->remaining;
    }

    public function redactBefore(string $cutoffUtc, int $limit): int
    {
        $batch = min($limit, $this->remaining);
        $this->remaining -= $batch;

        return $batch;
    }

    public function recordRun(string $cutoffDate, int $redacted, string $actor): void
    {
        $this->runs[] = ['redacted' => $redacted, 'actor' => $actor, 'cutoff' => $cutoffDate];
    }
}
