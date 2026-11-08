<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * The requested slot is already taken.
 *
 * Two callers use this: the legacy single-slot booking flow, which knows only that
 * the slot went, and the resource scheduler, which knows which resource and which
 * quantum clashed. The optional details let the scheduler offer the next free
 * quantum without changing what the older flow throws or catches.
 */
final class SlotUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly int $resourceId = 0,
        public readonly string $slotStartUtc = '',
    ) {
        parent::__construct(
            $this->slotStartUtc === ''
                ? 'That time was just taken. Please choose another.'
                : sprintf('Resource %d is already booked at %s.', $resourceId, $slotStartUtc),
        );
    }
}
