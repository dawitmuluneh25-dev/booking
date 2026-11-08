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
     * Persist the booking, claim its slot and enqueue notifications atomically.
     *
     * @param list<OutboundMessage> $messages
     *
     * @throws SlotUnavailableException when the slot is already taken
     */
    public function create(Booking $booking, string $slotStartUtc, array $messages = []): void;

    public function findByReference(string $reference): ?Booking;

    /**
     * Apply a lifecycle change atomically: update the status only if it is
     * still the expected one, record an event, and release the slot when
     * cancelling.
     *
     * @param list<OutboundMessage> $messages
     *
     * @throws InvalidTransitionException when the stored status has changed
     *                                    since the booking was read
     */
    public function transition(
        Booking $booking,
        BookingStatus $target,
        string $actor,
        ?string $reason,
        array $messages = [],
    ): void;

    /** @return list<Booking> */
    public function upcoming(int $limit): array;

    /**
     * Claimed slots in a UTC window, keyed by `Y-m-d H:i:s` slot start.
     *
     * @return array<string, Booking>
     */
    public function slotMap(string $fromUtc, string $toUtc): array;
}
