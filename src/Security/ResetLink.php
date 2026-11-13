<?php

declare(strict_types=1);

namespace App\Security;

/**
 * A freshly issued password reset, on its way out of the system.
 *
 * Three things travel together and only together: the account it belongs to, the
 * token that will be checked against the stored hash, and the moment it stops
 * working. The token is returned once, here, because only its SHA-256 is stored —
 * nothing later in the system can recover it, which is the point.
 *
 * The account id is part of the link rather than looked up again by the caller: a
 * reset is an outbox message about an account (0039), and asking the email address
 * a second time to find the id would be a second chance to get the answer wrong.
 */
final class ResetLink
{
    public function __construct(
        public readonly int $userId,
        public readonly string $token,
        public readonly string $expiresAtUtc,
    ) {
    }
}
