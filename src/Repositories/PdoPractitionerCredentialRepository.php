<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\PractitionerCredential;
use App\Domain\PractitionerCredentialRepository;
use App\Domain\PractitionerCredentialStatus;
use App\Domain\PractitionerPrivilege;
use PDO;

/**
 * MySQL implementation of the credential register (Decision 6, AC-009).
 *
 * `forUser` is the query every clinical approval makes, so it is one statement for
 * the credentials and one for their privileges rather than a query per credential:
 * a practitioner may hold two or three verifications and the approval path must not
 * turn that into a loop of round trips.
 *
 * There is no delete method. A verification that has been recorded is evidence; it
 * is suspended, revoked or allowed to lapse, all of which leave the row readable.
 */
final class PdoPractitionerCredentialRepository implements PractitionerCredentialRepository
{
    private const CREDENTIAL_COLUMNS = 'id, user_id, credential_type, registration_number,
        qualifications, evidence_reference, institutional_privilege_status, supervision_restriction,
        status, verified_by_user_id, verified_at, effective_from, expires_on, created_at';

    private const PRIVILEGE_COLUMNS = 'id, credential_id, procedure_code, basis,
        delegated_from_user_id, supervision, effective_from, effective_to,
        granted_by_user_id, granted_at, note';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<PractitionerCredential> */
    public function forUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::CREDENTIAL_COLUMNS . ' FROM practitioner_credentials
              WHERE user_id = :user_id
              ORDER BY created_at DESC, id DESC'
        );
        $statement->execute(['user_id' => $userId]);

        $rows = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            return [];
        }

        $privileges = $this->privilegesFor($this->ids($rows));
        $credentials = [];

        foreach ($rows as $row) {
            $id = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $credentials[] = PractitionerCredential::fromRow($row, $privileges[$id] ?? []);
        }

        return $credentials;
    }

    public function findCredential(int $credentialId): ?PractitionerCredential
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::CREDENTIAL_COLUMNS . ' FROM practitioner_credentials WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $credentialId]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return PractitionerCredential::fromRow($row, $this->privilegesFor([$credentialId])[$credentialId] ?? []);
    }

    public function findPrivilege(int $privilegeId): ?PractitionerPrivilege
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::PRIVILEGE_COLUMNS . ' FROM practitioner_privileges WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $privilegeId]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? PractitionerPrivilege::fromRow($row) : null;
    }

    public function create(PractitionerCredential $credential): PractitionerCredential
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO practitioner_credentials
                (user_id, credential_type, registration_number, qualifications, evidence_reference,
                 institutional_privilege_status, supervision_restriction, status,
                 verified_by_user_id, verified_at, effective_from, expires_on, created_at, updated_at)
             VALUES
                (:user_id, :credential_type, :registration_number, :qualifications, :evidence_reference,
                 :institutional_privilege_status, :supervision_restriction, :status,
                 :verified_by_user_id, :verified_at, :effective_from, :expires_on, :created_at, :updated_at)'
        );

        $statement->execute([
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
            'effective_from' => $credential->startsOn(),
            'expires_on' => $credential->expiresOnDay(),
            'created_at' => $credential->createdAtUtc,
            'updated_at' => $credential->createdAtUtc,
        ]);

        return $credential->withIdentity((int) $this->pdo->lastInsertId());
    }

    public function changeStatus(
        int $credentialId,
        PractitionerCredentialStatus $status,
        string $occurredAtUtc,
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE practitioner_credentials
                SET status = :status, updated_at = :updated_at
              WHERE id = :id'
        );
        $statement->execute([
            'status' => $status->value,
            'updated_at' => $occurredAtUtc,
            'id' => $credentialId,
        ]);
    }

    public function grantPrivilege(PractitionerPrivilege $privilege): PractitionerPrivilege
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO practitioner_privileges
                (credential_id, procedure_code, basis, delegated_from_user_id, supervision,
                 effective_from, effective_to, granted_by_user_id, granted_at, note)
             VALUES
                (:credential_id, :procedure_code, :basis, :delegated_from_user_id, :supervision,
                 :effective_from, :effective_to, :granted_by_user_id, :granted_at, :note)'
        );

        $statement->execute([
            'credential_id' => $privilege->credentialId,
            'procedure_code' => $privilege->procedureCode,
            'basis' => $privilege->basis->value,
            'delegated_from_user_id' => $privilege->delegatedFromUserId,
            'supervision' => $privilege->supervision,
            'effective_from' => $privilege->startsOn(),
            'effective_to' => $privilege->endsOn(),
            'granted_by_user_id' => $privilege->grantedByUserId,
            'granted_at' => $privilege->grantedAtUtc,
            'note' => $privilege->note,
        ]);

        return $privilege->withIdentity((int) $this->pdo->lastInsertId());
    }

    public function endPrivilege(int $privilegeId, string $endedOn): void
    {
        // Guarded on an open window so two administrators ending the same privilege
        // cannot make the second one silently rewrite the first one's date.
        $statement = $this->pdo->prepare(
            'UPDATE practitioner_privileges
                SET effective_to = :effective_to
              WHERE id = :id AND effective_to IS NULL'
        );
        $statement->execute(['effective_to' => $endedOn, 'id' => $privilegeId]);
    }

    /**
     * Privileges for a set of credentials, grouped by credential id.
     *
     * @param list<int> $credentialIds
     *
     * @return array<int, list<PractitionerPrivilege>>
     */
    private function privilegesFor(array $credentialIds): array
    {
        if ($credentialIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($credentialIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT ' . self::PRIVILEGE_COLUMNS . ' FROM practitioner_privileges
              WHERE credential_id IN (' . $placeholders . ')
              ORDER BY procedure_code ASC, effective_from DESC, id DESC'
        );
        $statement->execute($credentialIds);

        $grouped = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $privilege = PractitionerPrivilege::fromRow($row);
            $grouped[$privilege->credentialId][] = $privilege;
        }

        return $grouped;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if (is_numeric($row['id'] ?? null)) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }
}
