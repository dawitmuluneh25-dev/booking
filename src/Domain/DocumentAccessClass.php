<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How widely a stored document may travel (SEC 6.7, DDS 0008).
 *
 * Recorded rather than inferred at read time so the classification that was in
 * force when the file was stored survives a later change to the kind mapping.
 */
enum DocumentAccessClass: string
{
    case ClinicalPrivate = 'clinical_private';
    case AdministrativePrivate = 'administrative_private';
    case Restricted = 'restricted';

    public function label(): string
    {
        return match ($this) {
            self::ClinicalPrivate => 'Clinical - private',
            self::AdministrativePrivate => 'Administrative - private',
            self::Restricted => 'Restricted',
        };
    }
}
