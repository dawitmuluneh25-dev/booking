<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Imaging available for review (IMG-001).
 *
 * `none_available` and `unknown` are separate values for the same reason the
 * assessment vocabularies separate them: one is a finding, the other is the state of
 * the question. A reviewer deciding whether an image exists needs to tell them apart.
 */
enum ImagingModality: string
{
    case Ultrasound = 'ultrasound';
    case Ct = 'ct';
    case Mri = 'mri';
    case Fluoroscopy = 'fluoroscopy';
    case Other = 'other';
    case NoneAvailable = 'none_available';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Ultrasound => 'Ultrasound',
            self::Ct => 'CT',
            self::Mri => 'MRI',
            self::Fluoroscopy => 'Fluoroscopy',
            self::Other => 'Other',
            self::NoneAvailable => 'None available',
            self::Unknown => 'Unknown',
        };
    }

    /** Whether the value names a study that could be reviewed. */
    public function isStudy(): bool
    {
        return $this !== self::NoneAvailable && $this !== self::Unknown;
    }
}
