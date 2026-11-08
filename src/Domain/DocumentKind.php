<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What a document is, not what it is called.
 *
 * The list mirrors `ck_documents_kind` (0009). Keeping the enum and the CHECK in
 * step means "imaging report" is a value this application can reason about — it
 * decides the access class below — rather than a free-text label somebody typed.
 */
enum DocumentKind: string
{
    case ImagingReport = 'imaging_report';
    case LabResult = 'lab_result';
    case ReferralLetter = 'referral_letter';
    case Consent = 'consent';
    case Identity = 'identity';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ImagingReport => 'Imaging report',
            self::LabResult => 'Laboratory result',
            self::ReferralLetter => 'Referral letter',
            self::Consent => 'Consent record',
            self::Identity => 'Identity document',
            self::Other => 'Other document',
        };
    }

    /**
     * Classification travels with the kind instead of being chosen by whoever
     * fills in the form: a caller cannot upload a passport as "clinical_private"
     * and widen who may read it (SEC 6.7, 10.4).
     */
    public function accessClass(): DocumentAccessClass
    {
        return match ($this) {
            self::Identity => DocumentAccessClass::Restricted,
            self::Consent => DocumentAccessClass::AdministrativePrivate,
            default => DocumentAccessClass::ClinicalPrivate,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
