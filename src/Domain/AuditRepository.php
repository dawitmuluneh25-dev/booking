<?php

declare(strict_types=1);

namespace App\Domain;

interface AuditRepository
{
    /** @return list<BookingEvent> newest first */
    public function recent(int $limit, string $reference = ''): array;

    public function count(string $reference = ''): int;
}
