<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Persistence boundary. Implementations must use prepared statements and must
 * not accept SQL fragments from callers.
 */
interface BookingRepository
{
    /**
     * Persist the booking and claim its slot atomically.
     *
     * @throws SlotUnavailableException when the slot is already taken
     */
    public function create(Booking $booking, string $slotStartUtc): void;

    public function findByReference(string $reference): ?Booking;

    /** @return list<Booking> */
    public function upcoming(int $limit): array;
}
