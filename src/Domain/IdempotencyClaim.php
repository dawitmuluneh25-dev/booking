<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The store's answer to "may I do this work?".
 *
 * A replay carries the reference of the record that was created the first time,
 * not a copy of the response, so the caller re-reads the live row.
 */
final class IdempotencyClaim
{
    private function __construct(
        public readonly IdempotencyState $state,
        public readonly ?string $reference,
        public readonly string $reason,
    ) {
    }

    public static function fresh(): self
    {
        return new self(IdempotencyState::New, null, '');
    }

    public static function replay(string $reference): self
    {
        return new self(IdempotencyState::Replay, $reference, '');
    }

    public static function conflict(string $reason): self
    {
        return new self(IdempotencyState::Conflict, null, $reason);
    }

    public function shouldProceed(): bool
    {
        return $this->state === IdempotencyState::New;
    }
}
