<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * Raised when the actor may not perform the action at all.
 *
 * Kept distinct from a transition refusal on purpose: "you are not allowed to do
 * this kind of thing" and "this referral is not in a state where that is
 * possible" are different answers, and a route should be able to turn the first
 * into 403 and the second into 409 without inspecting a message string.
 */
final class AuthorisationException extends RuntimeException
{
    public function __construct(
        public readonly Role $role,
        public readonly Permission $permission,
        public readonly string $reason = '',
    ) {
        parent::__construct(sprintf(
            'The %s role does not hold the %s permission.%s',
            $role->value,
            $permission->value,
            $reason === '' ? '' : ' ' . $reason,
        ));
    }

    /**
     * The role holds the permission but may not use it here. Kept separate so the
     * message does not claim a missing permission the user actually has.
     */
    public static function forObject(Role $role, Permission $permission, string $reason): self
    {
        $exception = new self($role, $permission, $reason);
        $exception->message = sprintf('The %s role may not perform this action. %s', $role->value, $reason);

        return $exception;
    }
}
