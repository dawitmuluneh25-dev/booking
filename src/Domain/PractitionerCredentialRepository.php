<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The credential register the approval check reads (Decision 6, AC-009, LP-003).
 *
 * Reads are per practitioner, because the only question the product asks is "may
 * this person approve this procedure today". There is no "all credentials" query
 * and deliberately no delete: a verification that has been recorded is evidence,
 * and evidence is ended, not removed.
 */
interface PractitionerCredentialRepository
{
    /**
     * Every credential recorded for one account, newest first, each with its
     * procedure privileges.
     *
     * @return list<PractitionerCredential>
     */
    public function forUser(int $userId): array;

    public function findCredential(int $credentialId): ?PractitionerCredential;

    public function findPrivilege(int $privilegeId): ?PractitionerPrivilege;

    public function create(PractitionerCredential $credential): PractitionerCredential;

    public function changeStatus(
        int $credentialId,
        PractitionerCredentialStatus $status,
        string $occurredAtUtc,
    ): void;

    public function grantPrivilege(PractitionerPrivilege $privilege): PractitionerPrivilege;

    /** Close an open privilege window. */
    public function endPrivilege(int $privilegeId, string $endedOn): void;
}
