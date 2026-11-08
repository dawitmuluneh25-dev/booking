<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Verification state of a referring physician.
 *
 * `pending` is the state a new profile starts in, and it grants nothing: an
 * unverified referrer can sign in and see their own account, but cannot submit a
 * referral or read one. That is the difference between "we have heard of you" and
 * "we accept clinical correspondence from you" (SEC 6.3, AUTH-02).
 */
enum PhysicianStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting verification',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Suspended => 'Suspended',
        };
    }

    /** Whether referrals may be submitted and read under this profile. */
    public function grantsReferralAuthority(): bool
    {
        return $this === self::Verified;
    }

    public function isFinal(): bool
    {
        return $this === self::Rejected;
    }
}
