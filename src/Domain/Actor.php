<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * The person a command is acting for.
 *
 * Passed explicitly rather than read from a session inside the domain, so a
 * command can be exercised by a test, a CLI task or a future queue worker with
 * exactly the authority it claims. The role is one of the user's roles; someone
 * who is both an administrator and a radiologist acts as one at a time, which is
 * what makes "administrative privilege is not clinical privilege" checkable
 * (SEC 4.3, WF-03).
 */
final class Actor
{
    public function __construct(
        public readonly int $userId,
        public readonly Role $role,
    ) {
        if ($userId <= 0) {
            throw new InvalidArgumentException('An actor must be an identified user.');
        }
    }

    public function can(Permission $permission): bool
    {
        return $this->role->can($permission);
    }
}
