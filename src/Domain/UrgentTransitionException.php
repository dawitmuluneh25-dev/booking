<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * Raised when an urgent case may not change the way the caller asked.
 *
 * The two axes share one exception because they are refused for the same reasons —
 * not in the transition table, wrong role, missing reason, or another person got
 * there first — and the axis name is carried so the message stays precise.
 */
final class UrgentTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $reason = '',
        public readonly string $axis = 'urgency',
    ) {
        $message = sprintf('Cannot move an urgent case (%s) from %s to %s.', $axis, $from, $to);

        if ($reason !== '') {
            $message .= ' ' . $reason;
        }

        parent::__construct($message);
    }

    public static function forActor(UrgentStatus $from, UrgentStatus $to, Role $role): self
    {
        return new self(
            $from->value,
            $to->value,
            sprintf('The %s role may not perform this transition.', $role->value),
        );
    }

    public static function forMissingReason(UrgentStatus $from, UrgentStatus $to): self
    {
        return new self($from->value, $to->value, 'A written reason is required for this transition.');
    }

    public static function forCoordination(
        UrgentCoordination $from,
        UrgentCoordination $to,
        string $reason,
    ): self {
        return new self($from->value, $to->value, $reason, 'coordination');
    }
}
