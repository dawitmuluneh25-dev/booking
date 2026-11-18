<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\AccountRequestAttempts;

/**
 * The rate-limit counter in an array.
 *
 * Times are compared as `Y-m-d H:i:s` strings, which is how the SQL compares them too,
 * so a test that advances the clock by an hour exercises the same boundary the
 * database would.
 */
final class InMemoryAccountRequestAttempts implements AccountRequestAttempts
{
    /** @var list<array{ip_hash: string, occurred_at: string}> */
    private array $rows = [];

    public function record(string $ipHash, string $occurredAtUtc): void
    {
        $this->rows[] = ['ip_hash' => $ipHash, 'occurred_at' => $occurredAtUtc];
    }

    public function countSince(string $ipHash, string $sinceUtc): int
    {
        $count = 0;

        foreach ($this->rows as $row) {
            if ($row['ip_hash'] === $ipHash && $row['occurred_at'] >= $sinceUtc) {
                $count++;
            }
        }

        return $count;
    }

    public function purgeOlderThan(string $cutoffUtc): int
    {
        $before = count($this->rows);

        $this->rows = array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => $row['occurred_at'] >= $cutoffUtc,
        ));

        return $before - count($this->rows);
    }
}
