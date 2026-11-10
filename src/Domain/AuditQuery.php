<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Filters for the audit viewer.
 *
 * Every field is optional and empty means "no filter", so an unfiltered page is
 * the same query with fewer predicates rather than a separate code path.
 *
 * `fromUtc` and `toUtc` are already converted: the controller turns the dates a
 * person typed in the clinic zone into UTC boundaries before building this, so
 * the repository never has to know which zone the reader is in (ADR-004).
 */
final class AuditQuery
{
    public const MAX_LIMIT = 200;

    public function __construct(
        public readonly string $actor = '',
        public readonly string $action = '',
        public readonly string $objectType = '',
        public readonly string $objectRef = '',
        public readonly string $fromUtc = '',
        public readonly string $toUtc = '',
        public readonly int $limit = 100,
    ) {
    }

    public function withLimit(int $limit): self
    {
        return new self(
            actor: $this->actor,
            action: $this->action,
            objectType: $this->objectType,
            objectRef: $this->objectRef,
            fromUtc: $this->fromUtc,
            toUtc: $this->toUtc,
            limit: max(1, min($limit, self::MAX_LIMIT)),
        );
    }

    public function isEmpty(): bool
    {
        return $this->actor === ''
            && $this->action === ''
            && $this->objectType === ''
            && $this->objectRef === ''
            && $this->fromUtc === ''
            && $this->toUtc === '';
    }
}
