<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * Raised when a lifecycle change is not allowed from the current state — either
 * because the transition is not defined, or because another process changed the
 * status first.
 */
final class InvalidTransitionException extends RuntimeException
{
    public function __construct(public readonly BookingStatus $from, public readonly BookingStatus $to)
    {
        parent::__construct(sprintf(
            'Cannot change a booking from %s to %s.',
            $from->value,
            $to->value,
        ));
    }
}
