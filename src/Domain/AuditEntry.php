<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One entry about to be written to the audit trail.
 *
 * Deliberately narrow: the before/after maps carry identifiers and vocabulary
 * values (`role`, `status`, `email`), never a patient name, a phone number or
 * clinical free text. A command that wants to record the *content* of a clinical
 * decision is in the wrong table — that belongs in `clinical_decisions`, which is
 * already append-only and already authorised.
 */
final class AuditEntry
{
    /**
     * @param array<string, scalar|null>|null $before
     * @param array<string, scalar|null>|null $after
     */
    public function __construct(
        public readonly AuditAction $action,
        public readonly string $objectType,
        public readonly string $objectRef,
        public readonly ?int $actorUserId = null,
        public readonly ?Role $actorRole = null,
        public readonly ?array $before = null,
        public readonly ?array $after = null,
        public readonly string $occurredAtUtc = '',
        public readonly ?string $requestId = null,
    ) {
    }

    /**
     * The common case: an authenticated actor doing something to one object.
     *
     * @param array<string, scalar|null>|null $before
     * @param array<string, scalar|null>|null $after
     */
    public static function byActor(
        Actor $actor,
        AuditAction $action,
        string $objectType,
        string $objectRef,
        ?array $before = null,
        ?array $after = null,
        ?string $occurredAtUtc = null,
    ): self {
        return new self(
            action: $action,
            objectType: $objectType,
            objectRef: $objectRef,
            actorUserId: $actor->userId,
            actorRole: $actor->role,
            before: $before,
            after: $after,
            occurredAtUtc: $occurredAtUtc ?? gmdate('Y-m-d H:i:s'),
        );
    }
}
