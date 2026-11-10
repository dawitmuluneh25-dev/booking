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
    case ReferralReceived = 'referral_received';
    case AppointmentBooked = 'appointment_booked';
    case AppointmentMoved = 'appointment_moved';
    case AppointmentCancelled = 'appointment_cancelled';

    public function label(): string
    {
        return match ($this) {
            self::BookingRequested => 'Booking request received',
            self::BookingConfirmed => 'Booking confirmed',
            self::BookingCancelled => 'Booking cancelled',
            self::ReferralReceived => 'Referral received',
            self::AppointmentBooked => 'Appointment booked',
            self::AppointmentMoved => 'Appointment moved',
            self::AppointmentCancelled => 'Appointment cancelled',
        };
    }
}
