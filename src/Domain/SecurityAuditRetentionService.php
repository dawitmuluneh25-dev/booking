<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Applies the `security_and_audit` class of the retention schedule (D-02: 2,557 days).
 *
 * **The thing this sweep does that no other one does: it deletes evidence.** Every earlier class holds
 * records *about* something — a booking, a referral, a document, a dispatch run. This one holds the
 * record of what the system did, including who changed the rows in the others. A referral deleted
 * under its own period leaves an audit trail saying who authorised the deletion; an audit row deleted
 * under this period leaves nothing behind it, because it is the last layer.
 *
 * That is why the period is seven years and why it is at least as long as every class it describes —
 * `RetentionScheduleTest` asserts that as an invariant. It is also why the run record matters more
 * here than elsewhere: `retention_runs` is the only place that can later say how many audit rows went
 * and when, once the rows themselves are gone.
 *
 * **There is no legal-hold check, and that is a decided gap, not an oversight.** An investigation,
 * complaint or court order that reaches back seven years should stop a deletion that this sweep would
 * otherwise perform. The decision on 26 November 2026 was to sweep on the adopted period without a
 * hold mechanism; the consequence is recorded in `docs/operations/retention-validation.md` §5c so it is
 * not mistaken for a solved problem.
 *
 * Batched, and reports `remaining`, so a backlog drains over several runs rather than inside one
 * long-lived transaction.
 */
final class SecurityAuditRetentionService
{
    public function __construct(
        private readonly SecurityAuditRetentionRepository $repository,
        private readonly RetentionPolicy $policy,
    ) {
    }

    /**
     * @return array{eligible: int, by_table: array<string, int>, cutoff: string, days: int}
     */
    public function plan(): array
    {
        $cutoff = $this->policy->cutoff();
        $byTable = $this->repository->eligibleCounts($cutoff->format('Y-m-d H:i:s'));

        return [
            'eligible' => array_sum($byTable),
            'by_table' => $byTable,
            'cutoff' => $cutoff->format('Y-m-d H:i:s'),
            'days' => $this->policy->days,
        ];
    }

    /**
     * @return array{removed: int, by_table: array<string, int>, batches: int, cutoff: string, remaining: bool}
     */
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
        $byTable = array_fill_keys(array_keys(SecurityAuditRetentionRepository::TABLES), 0);

        while ($batches < $maxBatches) {
            $removed = $this->repository->removeBefore($cutoffUtc, $batchSize);
            $removedThisBatch = array_sum($removed);

            if ($removedThisBatch === 0) {
                $remaining = false;
                break;
            }

            foreach ($removed as $table => $count) {
                $byTable[$table] = ($byTable[$table] ?? 0) + $count;
            }

            $total += $removedThisBatch;
            $batches++;

            // Any table still at the ceiling means more is waiting, even if another table ran dry.
            $remaining = false;

            foreach ($removed as $count) {
                if ($count >= $batchSize) {
                    $remaining = $batches >= $maxBatches;
                }
            }

            if ($removedThisBatch < $batchSize) {
                break;
            }
        }

        // Recorded only when something moved, like every other sweep: a zero row is indistinguishable
        // from a sweep that never looked, and this table would otherwise grow by one row a day to say
        // nothing happened.
        if ($total > 0) {
            $this->repository->recordRun($cutoff->format('Y-m-d'), $byTable, $actor);
        }

        return [
            'removed' => $total,
            'by_table' => $byTable,
            'batches' => $batches,
            'cutoff' => $cutoffUtc,
            'remaining' => $remaining,
        ];
    }
}
