<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Change what a user may do, and end their sessions at the same time.
 *
 * Refusals:
 * - the actor must hold users.manage;
 * - nobody changes their own role. An administrator who could escalate themselves
 *   makes the role column advisory, and no audit trail makes that acceptable
 *   (SEC 6.8, Phase 3 acceptance 3).
 *
 * The revocation is not a separate action. `UserRepository::changeRole()` bumps
 * `auth_version` in the same statement, so there is no window in which the role is
 * new and the sessions are still the old one's.
 *
 * The audit row is written inside the same transaction as the change. A privilege
 * change that survives while its record does not is the one failure this command
 * must not have (F-04).
 */
final class ChangeUserRole
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {
    }

    /**
     * @throws AuthorisationException the actor may not manage users, or is the target
     * @throws UserNotFoundException  the target does not exist
     */
    public function execute(Actor $actor, int $targetUserId, Role $role): User
    {
        if (!$actor->can(Permission::UsersManage)) {
            throw new AuthorisationException($actor->role, Permission::UsersManage);
        }

        if ($actor->userId === $targetUserId) {
            throw AuthorisationException::forObject(
                $actor->role,
                Permission::UsersManage,
                'You cannot change your own role.',
            );
        }

        $target = $this->users->findById($targetUserId);

        if ($target === null) {
            throw new UserNotFoundException($targetUserId);
        }

        if ($target->role === $role) {
            return $target;
        }

        $this->transaction->run(function () use ($actor, $target, $targetUserId, $role): void {
            $this->users->changeRole($targetUserId, $role);

            $this->audit->record(AuditEntry::byActor(
                actor: $actor,
                action: AuditAction::UserRoleChanged,
                objectType: 'user',
                objectRef: (string) $targetUserId,
                before: ['role' => $target->role->value, 'email' => $target->email],
                after: ['role' => $role->value, 'email' => $target->email],
            ));
        });

        $updated = $this->users->findById($targetUserId);

        if ($updated === null) {
            throw new UserNotFoundException($targetUserId);
        }

        return $updated;
    }
}
