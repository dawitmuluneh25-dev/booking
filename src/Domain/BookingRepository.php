<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Persistence boundary. Implementations must use prepared statements and must
 * not accept SQL fragments from callers.
 */
interface BookingRepository
{
    public function create(Booking $booking): void;

    public function findByReference(string $reference): ?Booking;

    /** @return list<Booking> */
    public function upcoming(int $limit): array;
}
