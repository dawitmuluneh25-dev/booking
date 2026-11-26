<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\DispatchRunRetentionRepository;
use PDO;
use Throwable;

/**
 * Removes dispatch-ledger rows past their retention period.
 *
 * `eligibleCount` and `removeBefore` share one predicate — `started_at < :cutoff` — deliberately.
 * Two slightly different conditions is how a plan reports 40 rows and a purge removes 12 of them,
 * and nobody finds out until an operator compares the two numbers a year later.
 *
 * The delete is by primary key after a `FOR UPDATE` read, not `DELETE ... WHERE started_at < x LIMIT n`.
 * MySQL accepts `LIMIT` on a single-table delete, but row order then depends on the optimiser's choice
 * of index rather than on `started_at`, so "oldest first" would be a claim about the plan instead of a
 * property of the statement.
 */
final class PdoDispatchRunRetentionRepository implements DispatchRunRetentionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function eligibleCount(string $cutoffUtc): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM dispatch_runs WHERE started_at < :cutoff'
        );
        $statement->execute(['cutoff' => $cutoffUtc]);

        return (int) $statement->fetchColumn();
    }

    public function removeBefore(string $cutoffUtc, int $limit): int
    {
        $limit = max(1, $limit);

        $this->pdo->beginTransaction();

        try {
            $select = $this->pdo->prepare(
                'SELECT id FROM dispatch_runs
                  WHERE started_at < :cutoff
                  ORDER BY started_at
                  LIMIT :limit
                  FOR UPDATE'
            );
            $select->bindValue('cutoff', $cutoffUtc);
            $select->bindValue('limit', $limit, PDO::PARAM_INT);
            $select->execute();

            $ids = [];

            foreach ($select->fetchAll() as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $id = $row['id'] ?? null;

                if (is_int($id) || is_string($id)) {
                    $ids[] = (int) $id;
                }
            }

            if ($ids === []) {
                $this->pdo->commit();

                return 0;
            }

            $placeholders = [];

            foreach (array_keys($ids) as $index) {
                $placeholders[] = ':id' . $index;
            }

            $delete = $this->pdo->prepare(
                'DELETE FROM dispatch_runs WHERE id IN (' . implode(', ', $placeholders) . ')'
            );

            foreach ($ids as $index => $id) {
                $delete->bindValue(':id' . $index, $id, PDO::PARAM_INT);
            }

            $delete->execute();
            $removed = $delete->rowCount();

            $this->pdo->commit();

            return $removed;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function recordRun(string $cutoffDate, int $removed, string $actor): void
    {
        // The four older counters have no default on the original table, so they are stated as zero.
        // Leaving them out would make a ledger sweep indistinguishable from a booking sweep that
        // happened to touch nothing.
        $statement = $this->pdo->prepare(
            'INSERT INTO retention_runs
                (cutoff_date, bookings_redacted, referrals_redacted, documents_redacted,
                 notifications_redacted, dispatch_runs_removed, actor, ran_at)
             VALUES
                (:cutoff_date, 0, 0, 0, 0, :removed, :actor, :ran_at)'
        );
        $statement->execute([
            'cutoff_date' => $cutoffDate,
            'removed' => $removed,
            'actor' => $actor,
            'ran_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
