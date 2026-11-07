<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Booking;
use App\Domain\BookingRepository;

/**
 * Test double for the repository port: no database, deterministic ordering.
 */
final class InMemoryBookingRepository implements BookingRepository
{
    /** @var list<Booking> */
    public array $created = [];

    public function create(Booking $booking): void
    {
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
