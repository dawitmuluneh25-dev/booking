<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Allergies and prior reactions (MED-004).
 *
 * The package's warning is specific: never default blank to "none". An empty field
 * on a form means nobody asked, so `unknown` and `not_yet_assessed` sit beside the
 * two clinical answers rather than being represented by the absence of a tick.
 */
enum AllergyStatus: string
{
    case KnownAllergy = 'known_allergy';
    case NoKnownAllergyConfirmed = 'no_known_allergy_confirmed';
    case Unknown = 'unknown';
    case NotYetAssessed = 'not_yet_assessed';

    public function label(): string
    {
        return match ($this) {
            self::KnownAllergy => 'Known allergy',
            self::NoKnownAllergyConfirmed => 'None known — confirmed by asking',
            self::Unknown => 'Unknown',
            self::NotYetAssessed => 'Not yet assessed',
        };
    }

    public function assertsAllergy(): bool
    {
        return $this === self::KnownAllergy;
    }

    public function isEstablished(): bool
    {
        return $this === self::KnownAllergy || $this === self::NoKnownAllergyConfirmed;
    }
}
