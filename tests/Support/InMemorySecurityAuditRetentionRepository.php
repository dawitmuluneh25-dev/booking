<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\SecurityAuditRetentionRepository;

/**
 * A security sweep that records what it was asked to do instead of doing it.
 *
 * Counts are driven by a per-table seed the test sets, so a case can describe "forty audit rows and two
 * sign-in rows are eligible" without creating forty rows. The subtraction-on-remove matters: a repository
 * that reported the same count on every call would let a batching bug pass as a long sweep.
 */
final class InMemorySecurityAuditRetentionRepository implements SecurityAuditRetentionRepository
{
    /** @var array<string, int> */
    private array $remaining = [
        'audit_events' => 0,
        'login_attempts' => 0,
    ];

    /** @var list<array{cutoff_date: string, removed: array<string, int>, actor: string}> */
    private array $runs = [];

    public function seed(string $table, int $rows): void
    {
        $this->remaining[$table] = max(0, $rows);
    }

    public function eligibleCounts(string $cutoffUtc): array
    {
        return $this->remaining;
    }

    public function removeBefore(string $cutoffUtc, int $limitPerTable): array
    {
        $removed = [];

        foreach ($this->remaining as $table => $count) {
            $take = min($count, max(1, $limitPerTable));
            $this->remaining[$table] = $count - $take;
            $removed[$table] = $take;
        }

        return $removed;
    }

    public function recordRun(string $cutoffDate, array $removed, string $actor): void
    {
        $this->runs[] = ['cutoff_date' => $cutoffDate, 'removed' => $removed, 'actor' => $actor];
    }

    /** @return list<array{cutoff_date: string, removed: array<string, int>, actor: string}> */
    public function runs(): array
    {
        return $this->runs;
    }
}
