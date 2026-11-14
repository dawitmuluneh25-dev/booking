<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One recorded verification of a practitioner's credential.
 *
 * The row carries its own justification: what was verified, against what evidence,
 * by whom, when it takes effect and when it lapses. A credential with no expiry is
 * allowed — a lifetime registration exists — but the absence is explicit rather
 * than a default nobody looked at.
 *
 * Privileges hang off the credential, so suspending one credential stops every
 * procedure under it in one write. That is the right granularity: a finding about
 * a practitioner's registration is not a finding about one technique.
 */
final class PractitionerCredential
{
    /**
     * @param list<PractitionerPrivilege> $privileges the procedures recorded under this credential
     */
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $credentialType,
        public readonly string $registrationNumber,
        public readonly ?string $qualifications,
        public readonly string $evidenceReference,
        public readonly ?string $institutionalPrivilegeStatus,
        public readonly ?string $supervisionRestriction,
        public readonly PractitionerCredentialStatus $status,
        public readonly int $verifiedByUserId,
        public readonly string $verifiedAtUtc,
        public readonly string $effectiveFrom,
        public readonly ?string $expiresOn,
        public readonly string $createdAtUtc,
        public readonly array $privileges = [],
    ) {
    }

    /** The same record with the identity the database assigned it. */
    public function withIdentity(int $id): self
    {
        return new self(
            id: $id,
            userId: $this->userId,
            credentialType: $this->credentialType,
            registrationNumber: $this->registrationNumber,
            qualifications: $this->qualifications,
            evidenceReference: $this->evidenceReference,
            institutionalPrivilegeStatus: $this->institutionalPrivilegeStatus,
            supervisionRestriction: $this->supervisionRestriction,
            status: $this->status,
            verifiedByUserId: $this->verifiedByUserId,
            verifiedAtUtc: $this->verifiedAtUtc,
            effectiveFrom: $this->effectiveFrom,
            expiresOn: $this->expiresOn,
            createdAtUtc: $this->createdAtUtc,
            privileges: $this->privileges,
        );
    }

    public function withStatus(PractitionerCredentialStatus $status): self
    {
        return new self(
            id: $this->id,
            userId: $this->userId,
            credentialType: $this->credentialType,
            registrationNumber: $this->registrationNumber,
            qualifications: $this->qualifications,
            evidenceReference: $this->evidenceReference,
            institutionalPrivilegeStatus: $this->institutionalPrivilegeStatus,
            supervisionRestriction: $this->supervisionRestriction,
            status: $status,
            verifiedByUserId: $this->verifiedByUserId,
            verifiedAtUtc: $this->verifiedAtUtc,
            effectiveFrom: $this->effectiveFrom,
            expiresOn: $this->expiresOn,
            createdAtUtc: $this->createdAtUtc,
            privileges: $this->privileges,
        );
    }

    public function startsOn(): string
    {
        return substr($this->effectiveFrom, 0, 10);
    }

    public function expiresOnDay(): ?string
    {
        return $this->expiresOn === null ? null : substr($this->expiresOn, 0, 10);
    }

    /**
     * Whether the recorded window includes `$day`.
     *
     * Status is deliberately not consulted here: the command decides what a
     * suspended or revoked status means, and a method that answered both questions
     * would be a second copy of that rule.
     */
    public function coversDay(string $day): bool
    {
        if ($day < $this->startsOn()) {
            return false;
        }

        $expires = $this->expiresOnDay();

        return $expires === null || $day <= $expires;
    }

    /** The privilege naming `$procedureCode`, if one is recorded at all. */
    public function privilegeFor(string $procedureCode): ?PractitionerPrivilege
    {
        foreach ($this->privileges as $privilege) {
            if ($privilege->procedureCode === $procedureCode) {
                return $privilege;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>       $row
     * @param list<PractitionerPrivilege> $privileges
     */
    public static function fromRow(array $row, array $privileges = []): self
    {
        return new self(
            id: self::intOf($row['id'] ?? null),
            userId: self::intOf($row['user_id'] ?? null),
            credentialType: self::stringOf($row['credential_type'] ?? null),
            registrationNumber: self::stringOf($row['registration_number'] ?? null),
            qualifications: self::nullable($row['qualifications'] ?? null),
            evidenceReference: self::stringOf($row['evidence_reference'] ?? null),
            institutionalPrivilegeStatus: self::nullable($row['institutional_privilege_status'] ?? null),
            supervisionRestriction: self::nullable($row['supervision_restriction'] ?? null),
            status: PractitionerCredentialStatus::tryFrom(self::stringOf($row['status'] ?? null))
                ?? PractitionerCredentialStatus::Suspended,
            verifiedByUserId: self::intOf($row['verified_by_user_id'] ?? null),
            verifiedAtUtc: self::stringOf($row['verified_at'] ?? null),
            effectiveFrom: self::stringOf($row['effective_from'] ?? null),
            expiresOn: self::nullable($row['expires_on'] ?? null),
            createdAtUtc: self::stringOf($row['created_at'] ?? null),
            privileges: $privileges,
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
