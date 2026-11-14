<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Close a procedure privilege's window (Decision 6).
 *
 * Ending is not deleting. The row keeps its start, its basis and its grantor, so a
 * decision taken while the privilege was open remains explainable afterwards —
 * which is the whole reason the register is append-only in spirit.
 *
 * The end date cannot precede the start date. Back-dating an end into a period the
 * privilege never covered would make the register describe an authority that never
 * existed; if the start was wrong, the correction is a new record, not a silent
 * rewriting of this one.
 */
final class EndPractitionerPrivilege
{
    public function __construct(
        private readonly PractitionerCredentialRepository $credentials,
        private readonly Transaction $transaction,
        private readonly AuditTrail $audit,
    ) {
    }

    /** @throws AuthorisationException|ValidationException|PractitionerCredentialNotFoundException */
    public function execute(
        Actor $actor,
        int $privilegeId,
        string $endedOn,
        ?string $nowUtc = null,
    ): PractitionerPrivilege {
        if (!$actor->can(Permission::ClinicalCredentialManage)) {
            throw new AuthorisationException($actor->role, Permission::ClinicalCredentialManage);
        }

        $privilege = $this->credentials->findPrivilege($privilegeId);

        if ($privilege === null) {
            throw new PractitionerCredentialNotFoundException('No privilege matches #' . $privilegeId . '.');
        }

        $endedOn = trim($endedOn);

        if (!$this->isIsoDate($endedOn)) {
            throw new ValidationException([
                'ended_on' => 'Say the day this privilege ends, as YYYY-MM-DD.',
            ]);
        }

        $existing = $privilege->endsOn();

        if ($existing !== null) {
            throw new ValidationException([
                'ended_on' => 'This privilege already ended on ' . $existing . '.',
            ]);
        }

        if ($endedOn < $privilege->startsOn()) {
            throw new ValidationException([
                'ended_on' => 'A privilege cannot end before the day it started ('
                    . $privilege->startsOn() . ').',
            ]);
        }

        $when = $nowUtc ?? gmdate('Y-m-d H:i:s');

        return $this->transaction->run(function () use ($actor, $privilege, $endedOn, $when): PractitionerPrivilege {
            $this->credentials->endPrivilege($privilege->id, $endedOn);

            $this->audit->record(AuditEntry::byActor(
                actor: $actor,
                action: AuditAction::PractitionerPrivilegeEnded,
                objectType: 'practitioner_privilege',
                objectRef: (string) $privilege->id,
                before: ['effective_to' => null],
                after: ['effective_to' => $endedOn, 'procedure_code' => $privilege->procedureCode],
                occurredAtUtc: $when,
            ));

            return $privilege->endingOn($endedOn);
        });
    }

    private function isIsoDate(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
