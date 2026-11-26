<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The persistence side of the `dispatch_runs` class (D-02: 1,825 days, from 26 November 2026).
 *
 * Unlike the outbox, this contract **does** have a delete, and the reason is worth stating rather than
 * inferred. An outbox row is kept because it is the delivery record and because `dedupe_key` stops a
 * retried dispatch sending twice. A run row is a timestamp, four counters and a hostname — nothing
 * identifies a person, nothing is unique, and nothing downstream depends on an old row existing.
 * Pseudonymising it would leave a row that says nothing, which is worse than removing it.
 *
 * `removeBefore` is a hard delete inside a transaction, batched like every other sweep so a long
 * backlog cannot hold one lock on a shared host with a 30-second budget.
 */
interface DispatchRunRetentionRepository
{
    /** Run rows older than the cutoff. */
    public function eligibleCount(string $cutoffUtc): int;

    /** Delete one batch, oldest first, and return how many rows went. */
    public function removeBefore(string $cutoffUtc, int $limit): int;

    /** Record the sweep in `retention_runs`, alongside the other class counts. */
    public function recordRun(string $cutoffDate, int $removed, string $actor): void;
}
