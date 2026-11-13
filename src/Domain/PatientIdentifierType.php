<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What kind of number a patient identifier is (`PAT-004`).
 *
 * §6.3 asks to "store identifier type and issuing facility where available" and
 * warns never to treat the number as globally unique unless governed. The type is
 * what makes that warning actionable: a hospital number is only unique inside the
 * hospital that issued it, so the facility is not decoration, it is the scope of
 * the identifier.
 */
enum PatientIdentifierType: string
{
    case HospitalNumber = 'hospital_number';

    case ClinicNumber = 'clinic_number';

    case ReferralNumber = 'referral_number';

    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::HospitalNumber => 'Hospital number',
            self::ClinicNumber => 'Clinic number',
            self::ReferralNumber => 'Referral number',
            self::Other => 'Other reference',
        };
    }
}
