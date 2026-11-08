<?php

declare(strict_types=1);

namespace App\Domain;

/** Moves a referral into `ClinicalReview`; the rules live in TriageReferral. */
final class StartClinicalReview extends AdministrativeReferralCommand
{
    protected function target(): ReferralStatus
    {
        return ReferralStatus::ClinicalReview;
    }
}
