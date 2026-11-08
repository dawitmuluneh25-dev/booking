<?php

declare(strict_types=1);

namespace App\Domain;

/** Moves a referral into `AdministrativeReview`; the rules live in TriageReferral. */
final class StartAdministrativeReview extends AdministrativeReferralCommand
{
    protected function target(): ReferralStatus
    {
        return ReferralStatus::AdministrativeReview;
    }
}
