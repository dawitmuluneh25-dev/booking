<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Append-only record of authority, credential and document decisions.
 *
 * `record()` throws rather than swallowing a failure. An application that keeps
 * working when its audit writes fail is an application whose audit cannot be
 * relied on in exactly the situation that matters — so callers wrap the state
 * change and the record in one transaction and let the failure surface.
 */
interface AuditTrail
{
    public function record(AuditEntry $entry): void;

    /** @return list<AuditEvent> newest first */
    public function search(AuditQuery $query): array;

    public function count(AuditQuery $query): int;
}
