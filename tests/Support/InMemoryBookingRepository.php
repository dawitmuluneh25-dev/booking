<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Booking;
use App\Domain\BookingRepository;
use App\Domain\SlotUnavailableException;

/**
 * Test double for the repository port: no database, deterministic ordering,
 * and the same slot-exclusivity contract as the PDO implementation.
 */
final class InMemoryBookingRepository implements BookingRepository
{
    /** @var list<Booking> */
    public array $created = [];

    /** @var array<string, string> slot start => booking reference */
    public array $slots = [];

    public function create(Booking $booking, string $slotStartUtc): void
    {
        if (isset($this->slots[$slotStartUtc])) {
            throw new SlotUnavailableException();
        }

        $this->slots[$slotStartUtc] = $booking->reference;
        $this->created[] = $booking;
    }

    public function findByReference(string $reference): ?Booking
    {
        foreach ($this->created as $booking) {
            if ($booking->reference === $reference) {
                return $booking;
            }
        }

        return null;
    }

    /** @return list<Booking> */
    public function upcoming(int $limit): array
    {
        return array_slice($this->created, 0, $limit);
    }
}
