<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\AccountRequestAttempts;
use PDO;

/**
 * Counters for the public form's per-network allowance.
 *
 * One row per admitted attempt, pruned after 24 hours. The hash is keyed (see
 * `AccountRequestThrottle::hashAddress`), so this table cannot be used to recover an
 * address by enumeration.
 */
final class PdoAccountRequestAttempts implements AccountRequestAttempts
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $ipHash, string $occurredAtUtc): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO account_request_attempts (ip_hash, occurred_at) VALUES (:ip_hash, :occurred_at)'
        );

        $statement->execute(['ip_hash' => $ipHash, 'occurred_at' => $occurredAtUtc]);
    }

    public function countSince(string $ipHash, string $sinceUtc): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM account_request_attempts
             WHERE ip_hash = :ip_hash AND occurred_at >= :since'
        );

        $statement->execute(['ip_hash' => $ipHash, 'since' => $sinceUtc]);

        return (int) $statement->fetchColumn();
    }

    public function purgeOlderThan(string $cutoffUtc): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM account_request_attempts WHERE occurred_at < :cutoff'
        );

        $statement->execute(['cutoff' => $cutoffUtc]);

        return $statement->rowCount();
    }
}
