<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One contact arrangement a coordinator may use during an urgent case.
 *
 * "Verified" is a separate fact from "recorded", with its own author, its own date
 * and its own note saying how the verifier checked. That separation is the whole
 * point: anybody can type a number, and Decision 4 asks whether somebody answers it.
 *
 * There is no timing column anywhere on this entity, deliberately — no interval, no
 * deadline, no ladder. Decision 4 refused them, and a field is the easiest way to
 * reintroduce a thing that was refused.
 */
final class EscalationRoute
{
    public function __construct(
        public readonly int $id,
        public readonly EscalationRouteKind $kind,
        public readonly string $label,
        public readonly string $contactName,
        public readonly string $contactPhone,
        public readonly bool $isStaffed,
        public readonly bool $isActive,
        public readonly ?int $verifiedByUserId,
        public readonly ?string $verifiedAtUtc,
        public readonly ?string $verificationNote,
        public readonly string $createdAtUtc,
    ) {
    }

    public function isVerified(): bool
    {
        return $this->verifiedAtUtc !== null;
    }

    /**
     * Whether this row is one of the arrangements that must exist for escalation to
     * be usable: active, verified, and of a kind Decision 4 requires.
     */
    public function countsTowardActivation(): bool
    {
        return $this->isActive
            && $this->isVerified()
            && $this->kind->requiredForActivation();
    }

    public function withIdentity(int $id): self
    {
        return new self(
            id: $id,
            kind: $this->kind,
            label: $this->label,
            contactName: $this->contactName,
            contactPhone: $this->contactPhone,
            isStaffed: $this->isStaffed,
            isActive: $this->isActive,
            verifiedByUserId: $this->verifiedByUserId,
            verifiedAtUtc: $this->verifiedAtUtc,
            verificationNote: $this->verificationNote,
            createdAtUtc: $this->createdAtUtc,
        );
    }

    public function verifiedBy(int $userId, string $note, string $atUtc): self
    {
        return new self(
            id: $this->id,
            kind: $this->kind,
            label: $this->label,
            contactName: $this->contactName,
            contactPhone: $this->contactPhone,
            isStaffed: $this->isStaffed,
            isActive: $this->isActive,
            verifiedByUserId: $userId,
            verifiedAtUtc: $atUtc,
            verificationNote: $note,
            createdAtUtc: $this->createdAtUtc,
        );
    }

    public function retired(): self
    {
        return new self(
            id: $this->id,
            kind: $this->kind,
            label: $this->label,
            contactName: $this->contactName,
            contactPhone: $this->contactPhone,
            isStaffed: $this->isStaffed,
            isActive: false,
            verifiedByUserId: $this->verifiedByUserId,
            verifiedAtUtc: $this->verifiedAtUtc,
            verificationNote: $this->verificationNote,
            createdAtUtc: $this->createdAtUtc,
        );
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            kind: EscalationRouteKind::tryFrom(is_scalar($row['kind'] ?? null) ? (string) $row['kind'] : '')
                ?? EscalationRouteKind::Primary,
            label: is_scalar($row['label'] ?? null) ? (string) $row['label'] : '',
            contactName: is_scalar($row['contact_name'] ?? null) ? (string) $row['contact_name'] : '',
            contactPhone: is_scalar($row['contact_phone'] ?? null) ? (string) $row['contact_phone'] : '',
            isStaffed: (bool) ($row['is_staffed'] ?? false),
            isActive: (bool) ($row['is_active'] ?? false),
            verifiedByUserId: is_numeric($row['verified_by_user_id'] ?? null)
                ? (int) $row['verified_by_user_id']
                : null,
            verifiedAtUtc: is_scalar($row['verified_at'] ?? null) && (string) $row['verified_at'] !== ''
                ? (string) $row['verified_at']
                : null,
            verificationNote: is_scalar($row['verification_note'] ?? null)
                && (string) $row['verification_note'] !== ''
                    ? (string) $row['verification_note']
                    : null,
            createdAtUtc: is_scalar($row['created_at'] ?? null) ? (string) $row['created_at'] : '',
        );
    }
}
