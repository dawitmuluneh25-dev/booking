<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\AuditEntry;
use App\Domain\AuditEvent;
use App\Domain\AuditQuery;
use App\Domain\AuditTrail;

/**
 * Keeps what was recorded so a unit test can assert on it without a database.
 *
 * `search()` deliberately returns nothing: the unit suite is about whether the
 * right entry was produced, and the reading side is covered against MySQL in
 * `tests/Feature/AuditTrailRepositoryTest.php`.
 */
final class RecordingAuditTrail implements AuditTrail
{
    /** @var list<AuditEntry> */
    private array $entries = [];

    public function record(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /** @return list<AuditEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return list<AuditEvent> */
    public function search(AuditQuery $query): array
    {
        return [];
    }

    public function count(AuditQuery $query): int
    {
        return count($this->entries);
    }
}
