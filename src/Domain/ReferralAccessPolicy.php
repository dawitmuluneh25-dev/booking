<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Who may see a given referral.
 *
 * The permission check answers "may this role touch referrals at all"; this
 * answers "may this person touch *this* referral". Both are needed: a verified
 * physician holds referral.submit, but must not read a colleague's referrals from
 * another institution (AUTH-03).
 *
 * The rules, and why each is what it is:
 *
 * - clinical and administrative staff see every referral, because the service is
 *   the unit that carries them;
 * - a referring physician sees a referral only if their own verified profile is
 *   the one it was submitted under, and an unverified or suspended profile sees
 *   none (AUTH-02);
 * - a patient sees the referrals they submitted, and a recorded guardian with
 *   acting authority sees the same set.
 *
 * A refusal is answered as 404 by the caller, never 403 (SEC 8.4): confirming that
 * a referral exists to somebody who may not see it is itself a disclosure.
 */
final class ReferralAccessPolicy
{
    public function canView(
        User $user,
        Referral $referral,
        ?PhysicianProfile $profile = null,
        bool $isActingGuardian = false,
    ): bool {
        return match ($user->role) {
            Role::Admin, Role::Staff, Role::Radiologist, Role::Coordinator => true,
            Role::Physician => $this->isTheirOwnReferral($referral, $profile),
            Role::Patient => $referral->submittedByUserId === $user->id || $isActingGuardian,
        };
    }

    /**
     * Whether the physician may submit at all. Kept here rather than in the submit
     * command so the two answers cannot drift: the same profile state governs
     * submitting and reading.
     */
    public function maySubmitAsPhysician(?PhysicianProfile $profile): bool
    {
        return $profile !== null && $profile->mayRefer();
    }

    private function isTheirOwnReferral(Referral $referral, ?PhysicianProfile $profile): bool
    {
        if ($profile === null || !$profile->mayRefer()) {
            return false;
        }

        return $referral->referringPhysicianProfileId === $profile->id;
    }
}
