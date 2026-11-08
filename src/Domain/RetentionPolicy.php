<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * How long personal data may be kept after an appointment is over.
 *
 * Only finished bookings are eligible: a request or a confirmed upcoming
 * appointment must keep its contact details, otherwise the clinic cannot do
 * its job. A floor of 30 days stops a mistyped environment variable from
 * deleting this month's records.
 */
final class RetentionPolicy
{
    public const MIN_DAYS = 30;

    public function __construct(public readonly int $days)
    {
        if ($days < self::MIN_DAYS) {
            throw new InvalidArgumentException(
                'Retention must be at least ' . self::MIN_DAYS . ' days; got ' . $days . '.'
            );
        }
    }

    public function cutoff(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $now->modify('-' . $this->days . ' days');
    }

    public function isEligible(string $status, string $scheduledAtUtc, ?DateTimeImmutable $now = null): bool
    {
        $state = BookingStatus::tryFrom($status);

        if ($state === null || !$state->isTerminal()) {
            return false;
        }

        $scheduled = new DateTimeImmutable($scheduledAtUtc, new DateTimeZone('UTC'));

        return $scheduled < $this->cutoff($now);
    }
}
