<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What the consent discussion came to.
 *
 * Deliberately not a boolean. §18.1 separates a refusal from an inability to
 * consent, and the two lead to different clinical decisions: a competent patient
 * who declines has decided, while a patient who cannot take part has not. An
 * `emergency` case is absent on purpose — §18.3 forbids a generic override, so an
 * emergency is recorded as `unable_to_consent` with the reason, and routed to a
 * person rather than approved by the software.
 */
enum ConsentOutcome: string
{
    case Agreed = 'agreed';
    case Declined = 'declined';
    case UnableToConsent = 'unable_to_consent';

    public function label(): string
    {
        return match ($this) {
            self::Agreed => 'Agreed',
            self::Declined => 'Declined',
            self::UnableToConsent => 'Unable to take part',
        };
    }

    /** Whether the record releases anything to be acted on. */
    public function isAgreement(): bool
    {
        return $this === self::Agreed;
    }
}
