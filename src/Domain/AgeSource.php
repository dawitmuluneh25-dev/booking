<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Who stated the age (`PAT-003`: "Record who supplied it").
 *
 * §6.3 requires the source and does not supply the vocabulary, so the values here
 * are this application's: the requirement is the document's, the list is ours. It
 * is deliberately coarse. The point is to answer "did the child's mother tell us,
 * or did a clerk estimate it from a form?", which is a question that changes how
 * much weight the number carries.
 */
enum AgeSource: string
{
    case Patient = 'patient';

    case ParentOrGuardian = 'parent_or_guardian';

    case Caregiver = 'caregiver';

    case ReferringClinician = 'referring_clinician';

    case Staff = 'staff';

    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $source): string => $source->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Patient => 'the patient',
            self::ParentOrGuardian => 'a parent or guardian',
            self::Caregiver => 'a caregiver',
            self::ReferringClinician => 'the referring clinician',
            self::Staff => 'hospital staff',
            self::Other => 'somebody else',
        };
    }
}
