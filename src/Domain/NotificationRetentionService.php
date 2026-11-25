<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Applies the notification class of the retention schedule (D-02: 365 days).
 *
 * The class is separate from the booking and referral sweeps because its trigger is different: those
 * two are about a record that is finished, this one is about a message that is old. A referral inside
 * its seven-year period still has notifications from its first week, and nothing before this service
 * looked at them.
 *
 * Batched for the same reason the others are: a shared host with a 30-second budget. The loop stops at
 * `maxBatches` rather than holding one long transaction, and reports `remaining` so an operator can run
 * it again instead of guessing.
 */
final class NotificationRetentionService
{
    public function __construct(
        private readonly NotificationRetentionRepository $repository,
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

    /** @return array{redacted: int, batches: int, cutoff: string, remaining: bool} */
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
            $redacted = $this->repository->redactBefore($cutoffUtc, $batchSize);

            if ($redacted === 0) {
                $remaining = false;
                break;
            }

            $total += $redacted;
            $batches++;

            if ($redacted < $batchSize) {
                $remaining = false;
                break;
            }

            // A full batch at the ceiling means more is waiting for the next run.
            $remaining = $batches >= $maxBatches;
        }

        if ($total > 0) {
            $this->repository->recordRun($cutoff->format('Y-m-d'), $total, $actor);
        }

        return [
            'redacted' => $total,
            'batches' => $batches,
            'cutoff' => $cutoffUtc,
            'remaining' => $remaining,
        ];
    }
}
