<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\PractitionerCredential;
use App\Domain\PractitionerCredentialRepository;
use App\Domain\PractitionerCredentialStatus;
use App\Domain\PractitionerPrivilege;

/**
 * In-memory credential register, mirroring the adapter's contract.
 *
 * The ordering matches MySQL (`created_at DESC, id DESC`) because the check reads
 * "the newest usable credential first", and a double that ordered differently would
 * let a test pass that the real register would refuse.
 */
final class InMemoryPractitionerCredentialRepository implements PractitionerCredentialRepository
{
    /** @var array<int, PractitionerCredential> */
    private array $credentials = [];

    /** @var array<int, list<PractitionerPrivilege>> */
    private array $privileges = [];

    private int $nextCredentialId = 1;

    private int $nextPrivilegeId = 1;

    /** @return list<PractitionerCredential> */
    public function forUser(int $userId): array
    {
        $rows = [];

        foreach ($this->credentials as $credential) {
            if ($credential->userId === $userId) {
                $rows[] = $credential;
            }
        }

        usort(
            $rows,
            static fn (PractitionerCredential $a, PractitionerCredential $b): int
                => [$b->createdAtUtc, $b->id] <=> [$a->createdAtUtc, $a->id],
        );

        return array_map(
            fn (PractitionerCredential $credential): PractitionerCredential => $this->withPrivileges($credential),
            $rows,
        );
    }

    public function findCredential(int $credentialId): ?PractitionerCredential
    {
        $credential = $this->credentials[$credentialId] ?? null;

        return $credential === null ? null : $this->withPrivileges($credential);
    }

    public function findPrivilege(int $privilegeId): ?PractitionerPrivilege
    {
        foreach ($this->privileges as $rows) {
            foreach ($rows as $privilege) {
                if ($privilege->id === $privilegeId) {
                    return $privilege;
                }
            }
        }

        return null;
    }

    public function create(PractitionerCredential $credential): PractitionerCredential
    {
        $stored = $credential->withIdentity($this->nextCredentialId++);
        $this->credentials[$stored->id] = $stored;
        $this->privileges[$stored->id] = [];

        return $stored;
    }

    public function changeStatus(
        int $credentialId,
        PractitionerCredentialStatus $status,
        string $occurredAtUtc,
    ): void {
        $credential = $this->credentials[$credentialId] ?? null;

        if ($credential === null) {
            return;
        }

        $this->credentials[$credentialId] = $credential->withStatus($status);
    }

    public function grantPrivilege(PractitionerPrivilege $privilege): PractitionerPrivilege
    {
        $stored = $privilege->withIdentity($this->nextPrivilegeId++);

        // Rebuilt rather than appended: PHPStan reads `[] =` on a list-typed
        // property as possibly producing a non-list, and the extra copy is a
        // cheaper answer than widening the type.
        $this->privileges[$stored->credentialId] = [
            ...($this->privileges[$stored->credentialId] ?? []),
            $stored,
        ];

        return $stored;
    }

    public function endPrivilege(int $privilegeId, string $endedOn): void
    {
        foreach ($this->privileges as $credentialId => $rows) {
            $updated = [];

            foreach ($rows as $privilege) {
                $updated[] = $privilege->id === $privilegeId
                    ? $privilege->endingOn($endedOn)
                    : $privilege;
            }

            // Rebuilt rather than written through two levels of key: the property is
            // a list, and a nested assignment is not provably one.
            $this->privileges[$credentialId] = $updated;
        }
    }

    /** Convenience for fixtures: a verified credential with one privilege. */
    public function seedApprover(
        int $userId,
        string $procedureCode,
        string $effectiveFrom = '2026-01-01',
        ?string $expiresOn = null,
        PractitionerCredentialStatus $status = PractitionerCredentialStatus::Verified,
        ?string $privilegeEndsOn = null,
    ): PractitionerCredential {
        $created = $this->create(new PractitionerCredential(
            id: 0,
            userId: $userId,
            credentialType: 'specialist_registration',
            registrationNumber: 'REG-' . $userId,
            qualifications: null,
            evidenceReference: 'Register entry checked by the clinical lead.',
            institutionalPrivilegeStatus: null,
            supervisionRestriction: null,
            status: $status,
            verifiedByUserId: $userId,
            verifiedAtUtc: '2026-01-01 08:00:00',
            effectiveFrom: $effectiveFrom,
            expiresOn: $expiresOn,
            createdAtUtc: '2026-01-01 08:00:00',
        ));

        $this->grantPrivilege(new PractitionerPrivilege(
            id: 0,
            credentialId: $created->id,
            procedureCode: $procedureCode,
            basis: \App\Domain\PractitionerPrivilegeBasis::Own,
            delegatedFromUserId: null,
            supervision: null,
            effectiveFrom: $effectiveFrom,
            effectiveTo: $privilegeEndsOn,
            grantedByUserId: $userId,
            grantedAtUtc: '2026-01-01 08:00:00',
            note: null,
        ));

        return $created;
    }

    private function withPrivileges(PractitionerCredential $credential): PractitionerCredential
    {
        return PractitionerCredential::fromRow([
            'id' => $credential->id,
            'user_id' => $credential->userId,
            'credential_type' => $credential->credentialType,
            'registration_number' => $credential->registrationNumber,
            'qualifications' => $credential->qualifications,
            'evidence_reference' => $credential->evidenceReference,
            'institutional_privilege_status' => $credential->institutionalPrivilegeStatus,
            'supervision_restriction' => $credential->supervisionRestriction,
            'status' => $credential->status->value,
            'verified_by_user_id' => $credential->verifiedByUserId,
            'verified_at' => $credential->verifiedAtUtc,
            'effective_from' => $credential->effectiveFrom,
            'expires_on' => $credential->expiresOn,
            'created_at' => $credential->createdAtUtc,
        ], $this->privileges[$credential->id] ?? []);
    }
}
