<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Decide whether a referring physician may send referrals.
 *
 * Refusals:
 * - the actor must hold users.manage;
 * - nobody verifies their own profile — the whole point of verification is that
 *   somebody else checked the practice number;
 * - a rejection carries a written reason, because the physician will ask why.
 *
 * Verification grants clinical correspondence authority and nothing else: it does
 * not change the account's role and does not let the physician see referrals that
 * were not submitted under their profile.
 */
final class VerifyPhysician
{
    public function __construct(private readonly PhysicianProfileRepository $profiles)
    {
    }

    /**
     * @throws AuthorisationException    the actor may not verify, or is the subject
     * @throws PhysicianReviewException  the profile does not exist
     * @throws ValidationException       a rejection has no reason
     */
    public function execute(
        Actor $actor,
        int $profileId,
        PhysicianStatus $decision,
        ?string $note = null,
        ?string $nowUtc = null,
    ): PhysicianProfile {
        if (!$actor->can(Permission::UsersManage)) {
            throw new AuthorisationException($actor->role, Permission::UsersManage);
        }

        $profile = $this->profiles->findById($profileId);

        if ($profile === null) {
            throw new PhysicianReviewException('No physician profile matches #' . $profileId . '.');
        }

        if ($profile->userId === $actor->userId) {
            throw AuthorisationException::forObject(
                $actor->role,
                Permission::UsersManage,
                'You cannot verify your own referring profile.',
            );
        }

        $note = $note === null ? null : trim($note);

        if ($decision === PhysicianStatus::Rejected && ($note === null || mb_strlen($note) < 10)) {
            throw new ValidationException([
                'note' => 'Say why this profile was rejected, in at least ten characters.',
            ]);
        }

        if ($profile->status === $decision) {
            return $profile;
        }

        return $this->profiles->review(
            $profile,
            $decision,
            $actor->userId,
            $note === '' ? null : $note,
            $nowUtc ?? gmdate('Y-m-d H:i:s'),
        );
    }
}
