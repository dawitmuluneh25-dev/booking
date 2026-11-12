<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How the person taking part in the consent discussion stands to the patient.
 *
 * §18.2 requires the relationship and the authority to be recorded separately,
 * because the relationship is a fact the person states and the authority is a fact
 * somebody verifies. Treating "parent" as authority to consent is the assumption
 * the section exists to prevent — an accompanying adult is not automatically a
 * legal guardian, and an adolescent's assent is not a guardian's consent.
 *
 * The vocabulary matches `patient_relationships.relationship` so the two records
 * cannot drift into saying different things about the same person.
 */
enum ConsentParticipant: string
{
    case Self = 'self';
    case Parent = 'parent';
    case Guardian = 'guardian';
    case Carer = 'carer';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Self => 'The patient',
            self::Parent => 'Parent',
            self::Guardian => 'Legal guardian',
            self::Carer => 'Carer',
            self::Other => 'Other representative',
        };
    }

    /**
     * Whether an agreement recorded through this participant needs verified
     * authority before it can stand. Only the patient themself does not.
     */
    public function needsVerifiedAuthority(): bool
    {
        return $this !== self::Self;
    }
}
