<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The quarantine state of a stored file (DDS 0008, SEC 14.1).
 *
 * A scan error is not a clean result: `error` is deliberately not servable, so a
 * scanner that crashed cannot quietly release a file nobody looked at. The only
 * states that permit delivery are `clean` and `not_required`.
 */
enum DocumentScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';
    case Error = 'error';
    case NotRequired = 'not_required';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting review',
            self::Clean => 'Released',
            self::Infected => 'Quarantined: failed review',
            self::Error => 'Quarantined: review error',
            self::NotRequired => 'No review required',
        };
    }

    public function isServable(): bool
    {
        return $this === self::Clean || $this === self::NotRequired;
    }

    /** The verdicts an authorised reviewer may record, as opposed to `pending`. */
    public function isReviewVerdict(): bool
    {
        return $this === self::Clean || $this === self::Infected || $this === self::Error;
    }
}
