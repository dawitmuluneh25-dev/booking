<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Applies the `dispatch_runs` class of the retention schedule (D-02: 1,825 days).
 *
 * This is the fourth class sweep and the first one whose act is deletion rather than redaction. It is
 * a separate service for the same reason the notification one is: its trigger is not "the record is
 * finished", it is "the entry is old". A dispatch that ran yesterday belongs to no referral and to no
 * appointment, and the only thing that ever made it interesting — whether the dispatcher was alive —
 * is answered by the newest row, which a five-year window never reaches.
 *
 * Batched, and reports `remaining`, so a host with a large backlog is emptied over several runs rather
 * than inside one transaction that could hold a lock past the request budget.
 */
final class DispatchRunRetentionService
{
    public function __construct(
        private readonly DispatchRunRetentionRepository $repository,
        private readonly RetentionPolicy $policy,
    ) {
    }

    /** @return array{eligible: int, cutoff: string, days: int} */
    public function plan(): array
    {
        $cutoff = $this->policy->cutoff();

        return [
            'eligible' => $this->repository->eligibleCount($cutoff->format('Y-m-d H:i:s')),
            'cutoff' => $cutoff->format('Y-m-d H:i:s'),
            'days' => $this->policy->days,
        ];
    }

    /** @return array{removed: int, batches: int, cutoff: string, remaining: bool} */
    public function purge(string $actor, int $batchSize = 500, int $maxBatches = 20): array
    {
        $actor = trim($actor);

        if ($actor === '') {
            $actor = 'retention:cli';
        }

        $cutoff = $this->policy->cutoff();
        $cutoffUtc = $cutoff->format('Y-m-d H:i:s');

        $batchSize = max(1, $batchSize);
        $maxBatches = max(1, $maxBatches);

        $total = 0;
        $batches = 0;
        $remaining = false;

        while ($batches < $maxBatches) {
            $removed = $this->repository->removeBefore($cutoffUtc, $batchSize);

            if ($removed === 0) {
                $remaining = false;
                break;
            }

            $total += $removed;
            $batches++;

            if ($removed < $batchSize) {
                $remaining = false;
                break;
            }

            // A full batch at the ceiling means more is waiting for the next run.
            $remaining = $batches >= $maxBatches;
        }

        // Recorded only when something moved, matching the other sweeps: a run row claiming zero is
        // indistinguishable from a sweep that never looked, and the table would grow by one row a day
        // to say nothing.
        if ($total > 0) {
            $this->repository->recordRun($cutoff->format('Y-m-d'), $total, $actor);
        }

        return [
            'removed' => $total,
            'batches' => $batches,
            'cutoff' => $cutoffUtc,
            'remaining' => $remaining,
        ];
    }
}
