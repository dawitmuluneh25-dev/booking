<?php

declare(strict_types=1);

namespace App\Domain;

use App\Validation\Validator;

/**
 * Record that somebody checked the guardian's authority (PAT-013, §6.3).
 *
 * The referral can name a guardian from intake onwards, and until this command has
 * run that name is only a claim: the person who brought the child, or the relative
 * who filled in the form. §6.3 assigns the check to reception, a clinician or
 * governance, and §18.2 forbids the software assuming it — so the verification is an
 * act with a time, an actor and a stated basis, never an inference from the surname
 * matching or the phone number being the same.
 *
 * Refusals:
 *
 *   1. `referral.triage` is required. Verifying authority is a staff act; a patient
 *      or a referrer cannot do it for themselves.
 *   2. There must be a guardian to verify. A referral with no guardian named has no
 *      authority to check, and recording one would invent the person.
 *   3. It cannot already be verified. Verification is a fact with a time; a second
 *      one would either overwrite the first account or silently disagree with it.
 *   4. The basis is required. "Verified" with nothing behind it is the checkbox this
 *      whole slice exists to avoid.
 */
final class VerifyGuardianAuthority
{
    public function __construct(
        private readonly ReferralRepository $referrals,
        private readonly Validator $validator,
    ) {
    }

    /**
     * @throws AuthorisationException      the actor is not staff
     * @throws ReferralNotFoundException   the referral does not exist
     * @throws ValidationException         the basis is unusable
     * @throws ConsentNotPermittedException there is no guardian, or it is already verified
     */
    public function execute(
        Actor $actor,
        int $referralId,
        string $basis,
        ?string $nowUtc = null,
    ): void {
        if (!$actor->can(Permission::ReferralTriage)) {
            throw new AuthorisationException($actor->role, Permission::ReferralTriage);
        }

        $referral = $this->referrals->findById($referralId);

        if ($referral === null) {
            throw new ReferralNotFoundException('#' . $referralId);
        }

        if ($referral->guardianName === null) {
            throw new ConsentNotPermittedException(
                $referral->status,
                'No guardian is named on this referral, so there is no authority to verify.',
            );
        }

        if ($this->isVerified($referral)) {
            throw new ConsentNotPermittedException(
                $referral->status,
                'This guardian has already been verified. A correction belongs in a new record, '
                . 'not an overwrite of who checked it and when.',
            );
        }

        $errors = $this->validator->validate(
            ['guardian_verification_basis' => trim($basis)],
            ['guardian_verification_basis' => 'required|min:10|max:160'],
        );

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->referrals->verifyGuardianAuthority(
            $referral->id,
            $actor,
            trim($basis),
            $nowUtc ?? gmdate('Y-m-d H:i:s'),
        );
    }

    /**
     * Whether the referral already carries a verification.
     *
     * The entity does not expose the columns yet, so this reads them through the
     * repository's own view of the row rather than adding a second source of truth.
     */
    private function isVerified(Referral $referral): bool
    {
        return $this->referrals->guardianVerification($referral->id) !== null;
    }
}
