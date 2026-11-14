<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Why this practice holds a privilege.
 *
 * §16.3 requires delegation and temporary coverage to be recorded rather than
 * implied, so the basis is a value somebody chose and not an inference from a
 * sentence in a note. `Own` needs nobody else named; the other two do, because a
 * delegated privilege without a delegator is a privilege from nowhere.
 */
enum PractitionerPrivilegeBasis: string
{
    case Own = 'own';
    case Delegated = 'delegated';
    case TemporaryCoverage = 'temporary_coverage';

    public function label(): string
    {
        return match ($this) {
            self::Own => 'Own privilege',
            self::Delegated => 'Delegated',
            self::TemporaryCoverage => 'Temporary coverage',
        };
    }

    /** Whether this basis has to name the practitioner whose privilege is lent. */
    public function requiresSource(): bool
    {
        return $this !== self::Own;
    }
}
