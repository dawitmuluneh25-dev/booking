<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Suspend, reinstate or revoke a recorded credential.
 *
 * Suspension is reversible because it is an instruction to stop until something is
 * looked at. Revocation is final in place: reinstating a revoked credential would
 * overwrite the finding that it should not have been relied on, so a practitioner
 * is credentialed again by recording a new verification with its own evidence.
 *
 * The new state is written with the actor's name and the time, because the question
 * a suspension raises later is "who stopped this, and when" — and a register whose
 * answer is a status column cannot answer it.
 */
final class ChangePractitionerCredentialStatus
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
        int $credentialId,
        PractitionerCredentialStatus $status,
        ?string $note = null,
        ?string $nowUtc = null,
    ): PractitionerCredential {
        if (!$actor->can(Permission::ClinicalCredentialManage)) {
            throw new AuthorisationException($actor->role, Permission::ClinicalCredentialManage);
        }

        $credential = $this->credentials->findCredential($credentialId);

        if ($credential === null) {
            throw new PractitionerCredentialNotFoundException('No credential matches #' . $credentialId . '.');
        }

        if ($credential->status === $status) {
            return $credential;
        }

        if ($credential->status->isFinal()) {
            throw new ValidationException([
                'status' => 'This credential was revoked, which is final. Record a new verification '
                    . 'with its own evidence instead of reinstating this one.',
            ]);
        }

        $note = $note === null ? null : trim($note);

        if ($status === PractitionerCredentialStatus::Revoked && ($note === null || mb_strlen($note) < 10)) {
            throw new ValidationException([
                'note' => 'Say why this credential was revoked, in at least ten characters. The '
                    . 'practitioner will be refused approval and will ask what happened.',
            ]);
        }

        $when = $nowUtc ?? gmdate('Y-m-d H:i:s');

        return $this->transaction->run(function () use (
            $actor,
            $credential,
            $status,
            $note,
            $when,
        ): PractitionerCredential {
            $this->credentials->changeStatus($credential->id, $status, $when);

            $this->audit->record(AuditEntry::byActor(
                actor: $actor,
                action: AuditAction::PractitionerCredentialStatusChanged,
                objectType: 'practitioner_credential',
                objectRef: (string) $credential->id,
                before: ['status' => $credential->status->value],
                after: ['status' => $status->value, 'note' => $note === '' ? null : $note],
                occurredAtUtc: $when,
            ));

            return $credential->withStatus($status);
        });
    }
}
