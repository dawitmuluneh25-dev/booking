<?php

declare(strict_types=1);

namespace App\Domain;

use JsonException;

/** A row of `audit_events`, already decoded for display. */
final class AuditEvent
{
    /**
     * @param array<string, scalar|null>|null $before
     * @param array<string, scalar|null>|null $after
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $actorUserId,
        public readonly string $actorEmail,
        public readonly string $actorRole,
        public readonly string $action,
        public readonly string $objectType,
        public readonly string $objectRef,
        public readonly ?array $before,
        public readonly ?array $after,
        public readonly string $occurredAtUtc,
        public readonly string $requestId,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::toInt($row['id'] ?? null),
            actorUserId: isset($row['actor_user_id']) ? self::toInt($row['actor_user_id']) : null,
            actorEmail: self::toString($row['actor_email'] ?? null),
            actorRole: self::toString($row['actor_role'] ?? null),
            action: self::toString($row['action'] ?? null),
            objectType: self::toString($row['object_type'] ?? null),
            objectRef: self::toString($row['object_ref'] ?? null),
            before: self::decode($row['before_json'] ?? null),
            after: self::decode($row['after_json'] ?? null),
            occurredAtUtc: self::toString($row['occurred_at'] ?? null),
            requestId: self::toString($row['request_id'] ?? null),
        );
    }

    /**
     * A one-line rendering of the change, for a table cell.
     *
     * Kept here rather than in the template so the "from → to" shape is testable
     * and the template cannot invent a field that was never stored.
     */
    public function changeSummary(): string
    {
        $after = $this->flatten($this->after);
        $before = $this->flatten($this->before);

        if ($after === '' && $before === '') {
            return '—';
        }

        if ($before === '') {
            return $after;
        }

        if ($after === '') {
            return $before . ' → —';
        }

        return $before . ' → ' . $after;
    }

    /** @param array<string, scalar|null>|null $values */
    private function flatten(?array $values): string
    {
        if ($values === null || $values === []) {
            return '';
        }

        $parts = [];
        foreach ($values as $key => $value) {
            if ($value === null || $value === '') {
                $parts[] = $key;
                continue;
            }

            $parts[] = $key . '=' . (is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
        }

        return implode(', ', $parts);
    }

    /** @return array<string, scalar|null>|null */
    private static function decode(mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $clean = [];
        foreach ($decoded as $key => $item) {
            if (!is_string($key) || !is_scalar($item)) {
                continue;
            }

            $clean[$key] = $item;
        }

        return $clean === [] ? null : $clean;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
