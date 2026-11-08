<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Applies the retention policy in bounded batches.
 *
 * Batches keep a large purge from holding one long transaction on a shared
 * host with a 30-second request/CLI budget; the loop simply continues on the
 * next run if the batch ceiling is reached.
 */
final class RetentionService
{
    public function __construct(
        private readonly RetentionRepository $repository,
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

            // Hit the batch ceiling with a full batch: more is waiting.
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
