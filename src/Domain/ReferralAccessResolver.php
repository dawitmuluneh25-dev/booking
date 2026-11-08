<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Answers "may this person see this referral" with the evidence the policy needs.
 *
 * The policy itself takes the verified profile and the guardian relationship as
 * arguments so it stays a pure function. Somebody has to look them up, and doing
 * that in every controller is how two of them end up disagreeing. This resolver
 * is that single place.
 */
final class ReferralAccessResolver
{
    public function __construct(
        private readonly ReferralAccessPolicy $policy,
        private readonly PhysicianProfileRepository $physicians,
        private readonly PatientRelationshipRepository $relationships,
    ) {
    }

    public function canView(User $user, Referral $referral): bool
    {
        return $this->policy->canView(
            $user,
            $referral,
            $this->profileFor($user),
            $this->actsFor($user, $referral),
        );
    }

    /**
     * The verified referrer record behind this account, if any.
     *
     * Only a physician can have one, so no other role pays for the query.
     */
    public function profileFor(User $user): ?PhysicianProfile
    {
        return $user->role === Role::Physician ? $this->physicians->findByUserId($user->id) : null;
    }

    /**
     * Whether the user holds recorded acting authority for the patient who
     * submitted this referral. The patient themselves is not their own guardian.
     */
    public function actsFor(User $user, Referral $referral): bool
    {
        if ($user->id === $referral->submittedByUserId) {
            return false;
        }

        return $this->relationships->actsFor($user->id, $referral->submittedByUserId);
    }
}
