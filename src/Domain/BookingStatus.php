<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Booking lifecycle. Transitions are declared here, not in controllers, so the
 * same rules apply to HTTP, CLI and future integrations.
 *
 *   requested → confirmed → completed
 *        ↘          ↘
 *              cancelled
 */
enum BookingStatus: string
{
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Confirmed => 'Confirmed',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Cancelled || $this === self::Completed;
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Completed, self::Cancelled],
            self::Cancelled, self::Completed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
