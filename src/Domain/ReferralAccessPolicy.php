<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Who may see a given referral.
 *
 * The permission check answers "may this role touch referrals at all"; this
 * answers "may this person touch *this* referral". Both are needed: a verified
 * physician holds referral.submit, but must not read a colleague's referrals (AUTH-03).
 *
 * The rules, and why each is what it is:
 *
 * - clinical and administrative staff see every referral, because the service is
 *   the unit that carries them;
 * - a referring physician sees a referral only if their own verified profile is
 *   the one it was submitted under, and an unverified or suspended profile sees
 *   none (AUTH-02);
 * - a patient sees the referrals they submitted, and a recorded guardian with
 *   acting authority sees the same set;
 * - the clinical support roles see none. `TECH`, `NUR` and `LAB` prepare a room,
 *   prepare a patient and handle a specimen; a referral carries the patient's name,
 *   telephone number, clinical summary and target site, none of which any of those
 *   tasks requires. When a readiness or specimen surface eventually needs to name the
 *   referral a specimen came from, it will need an identifier and a scoped rule of its
 *   own — not this one widened (Decision 5, ADR-023).
 *
 * Institution is not part of this decision anywhere, and must not become part of
 * it: it is free text on the profile, so treating it as a boundary would mean
 * enforcing tenancy with a string somebody typed (ADR-014).
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
            // No referral permission is granted to these three, so this is the second
            // gate agreeing with the first rather than a rule of its own. Writing it
            // out means the match cannot silently fall through for a role nobody
            // considered — which is how a new role acquires access it was never given.
            Role::Technician, Role::Nurse, Role::Laboratory => false,
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
