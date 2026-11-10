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
    public function __construct(
        private readonly PhysicianProfileRepository $profiles,
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {
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

        // A review ends in one of three states. `pending` is where a profile starts,
        // not a verdict, and recording it as one would put a decision in the audit
        // trail that nobody made.
        $action = match ($decision) {
            PhysicianStatus::Verified => AuditAction::PhysicianVerified,
            PhysicianStatus::Rejected => AuditAction::PhysicianRejected,
            PhysicianStatus::Suspended => AuditAction::PhysicianSuspended,
            PhysicianStatus::Pending => throw new ValidationException([
                'decision' => 'A review ends in verified, rejected or suspended.',
            ]),
        };

        $when = $nowUtc ?? gmdate('Y-m-d H:i:s');

        return $this->transaction->run(function () use (
            $actor,
            $profile,
            $decision,
            $note,
            $when,
            $action,
        ): PhysicianProfile {
            $reviewed = $this->profiles->review(
                $profile,
                $decision,
                $actor->userId,
                $note === '' ? null : $note,
                $when,
            );

            $this->audit->record(AuditEntry::byActor(
                actor: $actor,
                action: $action,
                objectType: 'physician_profile',
                objectRef: (string) $profile->id,
                before: ['status' => $profile->status->value],
                after: ['status' => $decision->value],
                occurredAtUtc: $when,
            ));

            return $reviewed;
        });
    }
}
