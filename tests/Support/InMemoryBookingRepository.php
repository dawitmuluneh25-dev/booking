<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Booking;
use App\Domain\BookingNotFoundException;
use App\Domain\BookingRepository;
use App\Domain\BookingStatus;
use App\Domain\InvalidTransitionException;
use App\Domain\OutboundMessage;
use App\Domain\SlotUnavailableException;

/**
 * Test double for the repository port: no database, deterministic ordering,
 * and the same slot-exclusivity and transition contracts as the PDO version.
 */
final class InMemoryBookingRepository implements BookingRepository
{
    /** @var list<Booking> */
    public array $created = [];

    /** @var array<string, string> slot start => booking reference */
    public array $slots = [];

    /** @var list<array{reference: string, from: string, to: string, actor: string, reason: ?string}> */
    public array $events = [];

    /** @var list<OutboundMessage> */
    public array $messages = [];

    public function create(Booking $booking, string $slotStartUtc, array $messages = []): void
    {
        if (isset($this->slots[$slotStartUtc])) {
            throw new SlotUnavailableException();
        }

        $this->slots[$slotStartUtc] = $booking->reference;
        $this->created[] = $booking;

        foreach ($messages as $message) {
            $this->messages[] = $message;
        }
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

    public function transition(
        Booking $booking,
        BookingStatus $target,
        string $actor,
        ?string $reason,
        array $messages = [],
    ): void {
        $current = $this->findByReference($booking->reference);
        if ($current === null) {
            throw new BookingNotFoundException($booking->reference);
        }

        // Mirrors the guarded UPDATE in the PDO implementation.
        if ($current->status !== $booking->status) {
            throw new InvalidTransitionException($booking->status, $target);
        }

        foreach ($this->created as $index => $stored) {
            if ($stored->reference === $booking->reference) {
                $this->created[$index] = $stored->withStatus($target);
            }
        }

        $this->events[] = [
            'reference' => $booking->reference,
            'from' => $booking->status->value,
            'to' => $target->value,
            'actor' => $actor,
            'reason' => $reason,
        ];

        if ($target === BookingStatus::Cancelled) {
            unset($this->slots[$current->scheduledAtUtc]);
        }

        foreach ($messages as $message) {
            $this->messages[] = $message;
        }
    }

    /** @return list<Booking> */
    public function upcoming(int $limit): array
    {
        return array_slice($this->created, 0, $limit);
    }

    /** @return array<string, Booking> */
    public function slotMap(string $fromUtc, string $toUtc): array
    {
        $map = [];

        foreach ($this->slots as $slot => $reference) {
            if ($slot < $fromUtc || $slot >= $toUtc) {
                continue;
            }

            $booking = $this->findByReference($reference);
            if ($booking !== null) {
                $map[$slot] = $booking;
            }
        }

        ksort($map);

        return $map;
    }
}
