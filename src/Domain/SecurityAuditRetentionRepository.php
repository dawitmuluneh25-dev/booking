<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The storage half of the `security_and_audit` retention class (D-02: 2,557 days).
 *
 * Three tables make up the class, and they are not alike:
 *
 *   - `audit_events` — who did what, to which object, with before and after payloads. This is the
 *     evidence trail. Removing a row here does not just delete a record, it removes the ability to
 *     answer "who authorised this" about whatever that row concerned.
 *   - `login_attempts` — an email address, an IP, and whether the attempt succeeded. Failed attempts
 *     against a real address are the most personally identifying rows in the system and the least
 *     useful after a year.
 * `account_request_attempts` is deliberately **not** in this class, although it looks like one of these
 * tables. It is emptied after one day by the rate-limit hygiene path in `retention:purge`, because its
 * purpose is to cap how often a stranger can hit the public form — not to keep a record. A seven-year
 * ceiling over a table that empties daily would be a number that never applies to anything.
 *
 * Each carries its own timestamp column (`occurred_at`, `created_at`, `occurred_at`), so the condition
 * is per table rather than shared. A single `created_at` assumption would silently match nothing on
 * one table and every row on another, which is the kind of quiet no-op a sweep is prone to.
 *
 * What this interface deliberately does not offer is a way to delete one row. The class period is the
 * only authority here: a caller that could remove an arbitrary audit row would be a caller that could
 * cover its tracks.
 */
interface SecurityAuditRetentionRepository
{
    /**
     * Logical table name => the column that says when the row happened.
     *
     * @var array<string, string>
     */
    public const TABLES = [
        'audit_events' => 'occurred_at',
        'login_attempts' => 'created_at',
    ];

    /**
     * @return array<string, int> eligible rows per table, keyed by {@see self::TABLES}
     */
    public function eligibleCounts(string $cutoffUtc): array;

    /**
     * Removes up to `$limitPerTable` rows from each table, oldest first.
     *
     * @return array<string, int> rows removed per table
     */
    public function removeBefore(string $cutoffUtc, int $limitPerTable): array;

    /** @param array<string, int> $removed rows removed per table, for the run record */
    public function recordRun(string $cutoffDate, array $removed, string $actor): void;
}
