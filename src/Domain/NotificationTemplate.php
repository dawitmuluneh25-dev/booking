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

    /**
     * The only message that carries a credential rather than a fact.
     *
     * A reset link is a bearer token in a URL: whoever reads it can take the
     * account. That makes it the one template whose body must not survive the
     * send, and naming the rule here keeps it next to the template instead of
     * leaving the dispatcher to remember which strings are dangerous.
     */
    case PasswordReset = 'password_reset';

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
            self::PasswordReset => 'Password reset',
        };
    }

    /** Whether the body holds something that must not outlive the send. */
    public function carriesCredential(): bool
    {
        return $this === self::PasswordReset;
    }
}
