<?php

declare(strict_types=1);

namespace App\Domain;

interface RetentionRepository
{
    /** Bookings whose personal data is still present and past the cutoff. */
    public function eligibleCount(string $cutoffUtc): int;

    /**
     * Redact one batch and return how many bookings were changed. Zero means
     * there is nothing left to do.
     */
    public function redactBefore(string $cutoffUtc, int $limit): int;

    public function recordRun(string $cutoffDate, int $redacted, string $actor): void;

    /** @return list<RetentionRun> newest first */
    public function recentRuns(int $limit): array;
}
