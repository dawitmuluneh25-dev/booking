<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\DispatchRunRetentionRepository;

/**
 * An in-memory stand-in for the dispatch-ledger sweep, so the batching rules can be tested without a
 * database.
 *
 * It reproduces the two properties the service depends on — `started_at < cutoff` and oldest-first —
 * and nothing else. A fake that also modelled transactions would be tested against itself; the real
 * locking behaviour is asserted in `DispatchRunRetentionTest` against MySQL, which is the only place
 * it can be true.
 */
final class InMemoryDispatchRunRetentionRepository implements DispatchRunRetentionRepository
{
    /** @var list<array{cutoff_date: string, removed: int, actor: string}> */
    public array $runs = [];

    /**
     * @param list<string> $stamps UTC timestamps, in whatever order the caller likes; `removeBefore`
     *                             sorts them, because oldest-first is a property of the contract and
     *                             not of the insertion order.
     */
    public function __construct(public array $stamps = [])
    {
    }

    public function eligibleCount(string $cutoffUtc): int
    {
        $count = 0;

        foreach ($this->stamps as $stamp) {
            if ($stamp < $cutoffUtc) {
                $count++;
            }
        }

        return $count;
    }

    public function removeBefore(string $cutoffUtc, int $limit): int
    {
        $eligible = [];

        foreach ($this->stamps as $stamp) {
            if ($stamp < $cutoffUtc) {
                $eligible[] = $stamp;
            }
        }

        sort($eligible);

        $take = array_slice($eligible, 0, max(1, $limit));

        if ($take === []) {
            return 0;
        }

        $remaining = [];

        foreach ($this->stamps as $stamp) {
            if (!in_array($stamp, $take, true)) {
                $remaining[] = $stamp;
            }
        }

        $this->stamps = $remaining;

        return count($take);
    }

    public function recordRun(string $cutoffDate, int $removed, string $actor): void
    {
        $this->runs[] = [
            'cutoff_date' => $cutoffDate,
            'removed' => $removed,
            'actor' => $actor,
        ];
    }
}
