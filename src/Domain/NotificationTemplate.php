<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Message intents. The dispatcher renders these later; enqueueing never depends
 * on a provider being reachable.
 */
enum NotificationTemplate: string
{
    case BookingRequested = 'booking_requested';
    case BookingConfirmed = 'booking_confirmed';
    case BookingCancelled = 'booking_cancelled';

    public function label(): string
    {
        return match ($this) {
            self::BookingRequested => 'Booking request received',
            self::BookingConfirmed => 'Booking confirmed',
            self::BookingCancelled => 'Booking cancelled',
        };
    }
}
