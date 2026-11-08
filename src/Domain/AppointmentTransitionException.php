<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * Raised when an appointment may not move as asked.
 *
 * Deliberately its own type rather than a reuse of the referral's: the two
 * lifecycles are separate (WF-15), and a caller catching "the appointment moved
 * on" should not accidentally catch "the referral moved on".
 */
final class AppointmentTransitionException extends RuntimeException
{
    public function __construct(
        public readonly AppointmentStatus $from,
        public readonly AppointmentStatus $to,
        public readonly string $reason = '',
    ) {
        $message = sprintf('Cannot move an appointment from %s to %s.', $from->value, $to->value);

        if ($reason !== '') {
            $message .= ' ' . $reason;
        }

        parent::__construct($message);
    }
}
