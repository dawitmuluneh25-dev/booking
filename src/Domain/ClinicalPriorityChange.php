<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One change to the clinical priority standing on a referral.
 *
 * Both values are kept. The referral column holds what stands now; this row holds
 * what stood before and why it moved, which is what makes an escalation auditable
 * rather than merely visible.
 */
final class ClinicalPriorityChange
{
    public function __construct(
        public readonly int $id,
        public readonly int $referralId,
        public readonly ?string $fromPriority,
        public readonly string $toPriority,
        public readonly string $reason,
        public readonly int $changedByUserId,
        public readonly string $changedAtUtc,
    ) {
    }

    public function fromLabel(): ?string
    {
        return $this->fromPriority === null ? null : Priority::labelFor($this->fromPriority);
    }

    public function toLabel(): string
    {
        return Priority::labelFor($this->toPriority);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $from = $row['from_priority'] ?? null;

        return new self(
            id: self::intOf($row['id'] ?? null),
            referralId: self::intOf($row['referral_id'] ?? null),
            fromPriority: is_scalar($from) && (string) $from !== '' ? (string) $from : null,
            toPriority: self::stringOf($row['to_priority'] ?? null),
            reason: self::stringOf($row['reason'] ?? null),
            changedByUserId: self::intOf($row['changed_by_user_id'] ?? null),
            changedAtUtc: self::stringOf($row['changed_at'] ?? null),
        );
    }

    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function stringOf(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
