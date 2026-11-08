<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The five clinical outcomes a radiologist may record.
 *
 * The wording is the database's (`ck_decisions_type`), so the enum and the table
 * cannot drift apart. `resultingStatus()` is the single mapping from decision to
 * referral state, which keeps the command from having to repeat it.
 */
enum ClinicalDecisionType: string
{
    case Approved = 'approved';
    case Deferred = 'deferred';
    case Declined = 'declined';
    case Redirected = 'redirected';
    case MoreInformation = 'more_information';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved for the procedure',
            self::Deferred => 'Deferred',
            self::Declined => 'Declined',
            self::Redirected => 'Redirected to another service',
            self::MoreInformation => 'More information requested',
        };
    }

    public function resultingStatus(): ReferralStatus
    {
        return match ($this) {
            self::Approved => ReferralStatus::ClinicallyApproved,
            self::Deferred => ReferralStatus::Deferred,
            self::Declined => ReferralStatus::Declined,
            self::Redirected => ReferralStatus::AlternativePathway,
            self::MoreInformation => ReferralStatus::AwaitingInformation,
        };
    }

    /**
     * A decision that names no destination, or asks for nothing in particular, is
     * not a decision the receiving side can act on.
     */
    public function requiresDetail(): bool
    {
        return $this === self::Redirected || $this === self::MoreInformation;
    }
}
