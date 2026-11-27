<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\SecurityAuditRetentionRepository;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Removes security and audit rows past their retention period.
 *
 * **One table per transaction, not one transaction for all three.** If the audit delete succeeds and
 * the login-attempt delete throws, a single transaction would roll the audit rows back — and the next
 * run would report the same counts as though nothing had happened. Per-table commits make the run
 * record honest: it can say which table moved.
 *
 * **Selected then deleted, not `DELETE ... LIMIT`.** MySQL accepts `LIMIT` on a single-table delete, but
 * the rows it picks follow the optimiser's index choice rather than the timestamp, so "oldest first"
 * would describe the plan rather than the statement. The id list is read `FOR UPDATE` and deleted by
 * primary key, so the oldest rows are the ones that go and a concurrent insert cannot slip into the
 * batch.
 */
final class PdoSecurityAuditRetentionRepository implements SecurityAuditRetentionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function eligibleCounts(string $cutoffUtc): array
    {
        $counts = [];

        foreach (SecurityAuditRetentionRepository::TABLES as $table => $column) {
            $statement = $this->prepare(
                'SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $column . '` < :cutoff'
            );
            $statement->execute(['cutoff' => $cutoffUtc]);

            $counts[$table] = (int) $statement->fetchColumn();
        }

        return $counts;
    }

    public function removeBefore(string $cutoffUtc, int $limitPerTable): array
    {
        $limit = max(1, $limitPerTable);
        $removed = [];

        foreach (SecurityAuditRetentionRepository::TABLES as $table => $column) {
            $removed[$table] = $this->removeFromTable($table, $column, $cutoffUtc, $limit);
        }

        return $removed;
    }

    public function recordRun(string $cutoffDate, array $removed, string $actor): void
    {
        // Every counter is stated, including the older ones as zero. A partial insert would make a
        // security sweep indistinguishable from a booking sweep that happened to touch nothing.
        $statement = $this->prepare(
            'INSERT INTO retention_runs
                (cutoff_date, bookings_redacted, referrals_redacted, documents_redacted,
                 notifications_redacted, dispatch_runs_removed, uploaded_documents_redacted,
                 audit_events_removed, login_attempts_removed,
                 actor, ran_at)
             VALUES
                (:cutoff_date, 0, 0, 0, 0, 0, 0,
                 :audit_events, :login_attempts,
                 :actor, :ran_at)'
        );

        $statement->execute([
            'cutoff_date' => $cutoffDate,
            'audit_events' => $removed['audit_events'] ?? 0,
            'login_attempts' => $removed['login_attempts'] ?? 0,
            'actor' => $actor,
            'ran_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function removeFromTable(string $table, string $column, string $cutoffUtc, int $limit): int
    {
        $this->pdo->beginTransaction();

        try {
            $select = $this->prepare(
                'SELECT id FROM `' . $table . '`
                  WHERE `' . $column . '` < :cutoff
                  ORDER BY `' . $column . '`
                  LIMIT :limit
                  FOR UPDATE'
            );
            $select->bindValue('cutoff', $cutoffUtc);
            $select->bindValue('limit', $limit, PDO::PARAM_INT);
            $select->execute();

            $ids = [];

            foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
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

            $delete = $this->prepare(
                'DELETE FROM `' . $table . '` WHERE id IN (' . implode(', ', $placeholders) . ')'
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

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);

        if ($statement === false) {
            throw new RuntimeException('The driver refused to prepare a retention statement.');
        }

        return $statement;
    }
}
