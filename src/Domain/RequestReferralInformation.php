<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Ask the referrer for something that is missing.
 *
 * Separate from the base class because the destination demands a written request:
 * "awaiting information" without saying what is awaited leaves the referral stuck
 * until somebody rings the practice.
 */
final class RequestReferralInformation extends AdministrativeReferralCommand
{
    /**
     * @throws AuthorisationException
     * @throws ReferralNotFoundException
     * @throws ValidationException      when nothing is actually requested
     * @throws ReferralTransitionException
     */
    public function request(
        Actor $actor,
        int $referralId,
        string $informationNeeded,
        ?string $nowUtc = null,
    ): Referral {
        return $this->triage->execute(
            $actor,
            $referralId,
            ReferralStatus::AwaitingInformation,
            $informationNeeded,
            $nowUtc,
        );
    }

    protected function target(): ReferralStatus
    {
        return ReferralStatus::AwaitingInformation;
    }
}
