<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One procedure a practitioner is verified to approve, with the window it holds.
 *
 * There is no status column. A privilege ends by acquiring an end date — a fact
 * with a day attached — rather than by being switched off in a column that a
 * reader has to combine with something else to interpret. The credential's status
 * is what suspends everything under it; a window is what ends one procedure.
 */
final class PractitionerPrivilege
{
    public function __construct(
        public readonly int $id,
        public readonly int $credentialId,
        public readonly string $procedureCode,
        public readonly PractitionerPrivilegeBasis $basis,
        public readonly ?int $delegatedFromUserId,
        public readonly ?string $supervision,
        public readonly string $effectiveFrom,
        public readonly ?string $effectiveTo,
        public readonly int $grantedByUserId,
        public readonly string $grantedAtUtc,
        public readonly ?string $note,
    ) {
    }

    /** The same privilege with the identity the database assigned it. */
    public function withIdentity(int $id): self
    {
        return new self(
            id: $id,
            credentialId: $this->credentialId,
            procedureCode: $this->procedureCode,
            basis: $this->basis,
            delegatedFromUserId: $this->delegatedFromUserId,
            supervision: $this->supervision,
            effectiveFrom: $this->effectiveFrom,
            effectiveTo: $this->effectiveTo,
            grantedByUserId: $this->grantedByUserId,
            grantedAtUtc: $this->grantedAtUtc,
            note: $this->note,
        );
    }

    /** The same privilege with its window closed on `$endedOn`. */
    public function endingOn(string $endedOn): self
    {
        return new self(
            id: $this->id,
            credentialId: $this->credentialId,
            procedureCode: $this->procedureCode,
            basis: $this->basis,
            delegatedFromUserId: $this->delegatedFromUserId,
            supervision: $this->supervision,
            effectiveFrom: $this->effectiveFrom,
            effectiveTo: $endedOn,
            grantedByUserId: $this->grantedByUserId,
            grantedAtUtc: $this->grantedAtUtc,
            note: $this->note,
        );
    }

    /**
     * The day the window opened, as a comparable string.
     *
     * Dates are kept as ISO `Y-m-d` strings so two of them compare with `<=` without
     * a timezone entering the question: a privilege covers a clinic day, not an
     * instant.
     */
    public function startsOn(): string
    {
        return substr($this->effectiveFrom, 0, 10);
    }

    /** The day the window closed, or null while it is open. */
    public function endsOn(): ?string
    {
        return $this->effectiveTo === null ? null : substr($this->effectiveTo, 0, 10);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::intOf($row['id'] ?? null),
            credentialId: self::intOf($row['credential_id'] ?? null),
            procedureCode: self::stringOf($row['procedure_code'] ?? null),
            basis: PractitionerPrivilegeBasis::tryFrom(self::stringOf($row['basis'] ?? null))
                ?? PractitionerPrivilegeBasis::Own,
            delegatedFromUserId: is_numeric($row['delegated_from_user_id'] ?? null)
                ? (int) $row['delegated_from_user_id']
                : null,
            supervision: self::nullable($row['supervision'] ?? null),
            effectiveFrom: self::stringOf($row['effective_from'] ?? null),
            effectiveTo: self::nullable($row['effective_to'] ?? null),
            grantedByUserId: self::intOf($row['granted_by_user_id'] ?? null),
            grantedAtUtc: self::stringOf($row['granted_at'] ?? null),
            note: self::nullable($row['note'] ?? null),
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

    private static function nullable(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
