<?php

declare(strict_types=1);

namespace App\Domain;

/** Moves a referral into `Received`; the rules live in TriageReferral. */
final class ReceiveElectiveReferral extends AdministrativeReferralCommand
{
    protected function target(): ReferralStatus
    {
        return ReferralStatus::Received;
    }
}
