<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * Raised when a referral change is not permitted: either the transition is not in
 * the controlled table (WFS 5.3), the actor's role may not perform it, or another
 * request advanced the referral first and the version no longer matches.
 */
final class ReferralTransitionException extends RuntimeException
{
    public function __construct(
        public readonly ReferralStatus $from,
        public readonly ReferralStatus $to,
        public readonly string $reason = '',
    ) {
        $message = sprintf('Cannot move a referral from %s to %s.', $from->value, $to->value);

        if ($reason !== '') {
            $message .= ' ' . $reason;
        }

        parent::__construct($message);
    }

    public static function forActor(ReferralStatus $from, ReferralStatus $to, Role $role): self
    {
        return new self(
            $from,
            $to,
            sprintf('The %s role may not perform this transition.', $role->value),
        );
    }

    public static function forMissingReason(ReferralStatus $from, ReferralStatus $to): self
    {
        return new self($from, $to, 'A written reason is required for this transition.');
    }
}
