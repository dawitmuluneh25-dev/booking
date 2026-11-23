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
        /** Opening hours as Operations states them, or null while the route is unstaffed. */
        public readonly ?string $operatingHours = null,
        /** What the receiving role is expected to do on receipt of an escalation. */
        public readonly ?string $acknowledgementExpectation = null,
        /** The route to use when this one does not answer. Never a substitute for a clinical one. */
        public readonly ?string $fallbackRoute = null,
    ) {
    }

    public function isVerified(): bool
    {
        return $this->verifiedAtUtc !== null;
    }

    /**
     * Whether anybody has been recorded against this route yet.
     *
     * D-06 seeds five categories before Operations supplies the roster, and those rows are inactive
     * without being retired. The two states look identical from `isActive` alone and mean opposite
     * things — "we stopped using this" and "we have not started" — so the distinction is made from
     * the contact rather than from the flag.
     */
    public function hasContact(): bool
    {
        return trim($this->contactName) !== '';
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
            operatingHours: $this->operatingHours,
            acknowledgementExpectation: $this->acknowledgementExpectation,
            fallbackRoute: $this->fallbackRoute,
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
            operatingHours: $this->operatingHours,
            acknowledgementExpectation: $this->acknowledgementExpectation,
            fallbackRoute: $this->fallbackRoute,
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
            operatingHours: $this->operatingHours,
            acknowledgementExpectation: $this->acknowledgementExpectation,
            fallbackRoute: $this->fallbackRoute,
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
            operatingHours: self::optional($row, 'operating_hours'),
            acknowledgementExpectation: self::optional($row, 'acknowledgement_expectation'),
            fallbackRoute: self::optional($row, 'fallback_route'),
        );
    }

    /**
     * A nullable column, treating '' as absent.
     *
     * D-06's register holds five categories before Operations fills any of them in, so the columns
     * added for those five have to survive being empty. An empty string renders as an empty cell and
     * reads as "nobody wrote this down"; null is the same fact stated once, and it is what
     * `documentControl()`-style code can test for.
     *
     * @param array<string, mixed> $row
     */
    private static function optional(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }
}
