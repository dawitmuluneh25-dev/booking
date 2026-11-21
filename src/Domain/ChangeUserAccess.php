<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Suspend an account, or restore it.
 *
 * Deactivation is not deletion, and that is the point. The row, its role, its audit
 * history and every clinical record it touches stay where they are; what changes is
 * that the account can no longer authenticate, and every live session ends on its
 * next request. Deleting the row instead would be blocked by the referential edges
 * that make the clinical record trustworthy — and if it were not blocked, the audit
 * trail would be the thing that lost its subject (plan §22).
 *
 * Refusals:
 * - the actor must hold `users.manage`; the console additionally refuses an actor whose
 *   own account is suspended, because a suspended account cannot suspend anybody;
 * - nobody changes their own access, for the reason nobody changes their own role:
 *   otherwise the control can be undone by the person it constrains.
 *
 * **Why there is no "last administrator" guard.** A clinical system with no active
 * administrator cannot approve a referral, verify a physician or add a user, and
 * nobody inside it can put that right — so the lockout deserves a guard. It already
 * has one, structurally: only `admin` holds `users.manage`, and the actor may not be
 * the target, so any caller is an active administrator *other than* the account being
 * suspended. That account therefore cannot be the last one. A branch asserting the
 * same thing would be unreachable, and an unreachable check is the defect this project
 * spent a slice removing from `doctor` (plan §21.6). The invariant is tested directly
 * instead of restated.
 *
 * `auth_version` moves in the same statement as `is_active` (see
 * `UserRepository::setActive()`), so there is no window in which the account is
 * suspended and its sessions are still alive.
 *
 * The audit row is written inside the transaction that changes the account. A
 * suspension that survives while its record does not is the failure this command must
 * not have (F-04).
 */
final class ChangeUserAccess
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
    public function execute(Actor $actor, int $targetUserId, bool $active): User
    {
        if (!$actor->can(Permission::UsersManage)) {
            throw new AuthorisationException($actor->role, Permission::UsersManage);
        }

        if ($actor->userId === $targetUserId) {
            throw AuthorisationException::forObject(
                $actor->role,
                Permission::UsersManage,
                'You cannot change your own access.',
            );
        }

        $target = $this->users->findById($targetUserId);

        if ($target === null) {
            throw new UserNotFoundException($targetUserId);
        }

        // Already suspended, or already active: nothing to write, and no audit row
        // pretending something happened.
        if ($target->active === $active) {
            return $target;
        }

        $this->transaction->run(function () use ($actor, $target, $targetUserId, $active): void {
            $this->users->setActive($targetUserId, $active);

            $this->audit->record(AuditEntry::byActor(
                actor: $actor,
                action: $active ? AuditAction::UserReactivated : AuditAction::UserDeactivated,
                objectType: 'user',
                objectRef: (string) $targetUserId,
                before: ['active' => $target->active, 'email' => $target->email, 'role' => $target->role->value],
                after: ['active' => $active, 'email' => $target->email, 'role' => $target->role->value],
            ));
        });

        $updated = $this->users->findById($targetUserId);

        if ($updated === null) {
            throw new UserNotFoundException($targetUserId);
        }

        return $updated;
    }
}
