<?php

declare(strict_types=1);

namespace App\Domain;

use App\Validation\Validator;
use DateTimeImmutable;

/**
 * Give a credential the right to approve one procedure, for a window (Decision 6).
 *
 * The scope is a procedure in the catalogue, not a phrase. That is what makes
 * "covers the requested clinical action" a comparison rather than an opinion: the
 * approval check joins the referral's procedure code to a privilege row and does
 * not read anybody's description of their own competence.
 *
 * Refusals:
 * - a suspended or revoked credential cannot acquire new privileges, because the
 *   thing the privilege hangs from has already been found wanting;
 * - the procedure must exist in the catalogue, so a privilege cannot name a
 *   service the clinic does not offer;
 * - delegation and temporary coverage name the practitioner whose privilege is
 *   lent (§16.3), and temporary coverage carries an end date — "temporary" with no
 *   end is permanent with a misleading label.
 */
final class GrantPractitionerPrivilege
{
    public function __construct(
        private readonly PractitionerCredentialRepository $credentials,
        private readonly ProcedureRepository $procedures,
        private readonly UserRepository $users,
        private readonly Validator $validator,
        private readonly Transaction $transaction,
        private readonly AuditTrail $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AuthorisationException|ValidationException|PractitionerCredentialNotFoundException
     */
    public function execute(
        Actor $actor,
        int $credentialId,
        array $input,
        ?string $nowUtc = null,
    ): PractitionerPrivilege {
        if (!$actor->can(Permission::ClinicalCredentialManage)) {
            throw new AuthorisationException($actor->role, Permission::ClinicalCredentialManage);
        }

        $credential = $this->credentials->findCredential($credentialId);

        if ($credential === null) {
            throw new PractitionerCredentialNotFoundException('No credential matches #' . $credentialId . '.');
        }

        if (!$credential->status->authorisesApproval()) {
            throw new ValidationException([
                'credential' => 'This credential is ' . $credential->status->label() . ', so it cannot '
                    . 'acquire a procedure privilege. Reinstate it first, or record a new verification.',
            ]);
        }

        $data = [
            'procedure_code' => $this->text($input, 'procedure_code'),
            'basis' => $this->text($input, 'basis'),
            'delegated_from_user_id' => $this->text($input, 'delegated_from_user_id'),
            'supervision' => $this->text($input, 'supervision'),
            'effective_from' => $this->text($input, 'effective_from'),
            'effective_to' => $this->text($input, 'effective_to'),
            'note' => $this->text($input, 'note'),
        ];

        $basis = PractitionerPrivilegeBasis::tryFrom($data['basis'] === '' ? 'own' : $data['basis']);

        $errors = $this->validator->validate($data, [
            'procedure_code' => 'required|max:40',
            'supervision' => 'max:300',
            'effective_from' => 'required',
            'effective_to' => 'max:10',
            'note' => 'max:500',
        ]);

        if ($basis === null) {
            $errors['basis'] = 'Choose how this privilege is held: '
                . implode(', ', array_map(
                    static fn (PractitionerPrivilegeBasis $case): string => $case->label(),
                    PractitionerPrivilegeBasis::cases(),
                )) . '.';
        }

        if ($data['procedure_code'] !== '' && $this->procedures->find($data['procedure_code']) === null) {
            $errors['procedure_code'] = 'Choose a procedure from the catalogue. A privilege has to '
                . 'name a service this clinic performs.';
        }

        $errors = $this->window($data['effective_from'], $data['effective_to'], $errors);

        $delegator = null;

        if ($data['delegated_from_user_id'] !== '') {
            $delegator = is_numeric($data['delegated_from_user_id'])
                ? $this->users->findById((int) $data['delegated_from_user_id'])
                : null;

            if ($delegator === null) {
                $errors['delegated_from_user_id'] = 'Name the practitioner whose privilege is being '
                    . 'lent, from the accounts this clinic holds.';
            }
        }

        if ($basis !== null && $basis->requiresSource() && $data['delegated_from_user_id'] === '') {
            $errors['delegated_from_user_id'] = 'A ' . strtolower($basis->label()) . ' privilege has '
                . 'to name the practitioner whose authority it borrows.';
        }

        if ($basis === PractitionerPrivilegeBasis::TemporaryCoverage && $data['effective_to'] === '') {
            $errors['effective_to'] = 'Temporary coverage has to end on a stated date. An open-ended '
                . 'arrangement is a permanent one with a temporary label.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $when = $nowUtc ?? gmdate('Y-m-d H:i:s');

        /** @var PractitionerPrivilegeBasis $basis */
        $privilege = new PractitionerPrivilege(
            id: 0,
            credentialId: $credential->id,
            procedureCode: $data['procedure_code'],
            basis: $basis,
            delegatedFromUserId: $delegator?->id,
            supervision: $this->nullable($data['supervision']),
            effectiveFrom: $data['effective_from'],
            effectiveTo: $this->nullable($data['effective_to']),
            grantedByUserId: $actor->userId,
            grantedAtUtc: $when,
            note: $this->nullable($data['note']),
        );

        return $this->transaction->run(function () use ($actor, $privilege, $when): PractitionerPrivilege {
            $stored = $this->credentials->grantPrivilege($privilege);

            $this->audit->record(AuditEntry::byActor(
                actor: $actor,
                action: AuditAction::PractitionerPrivilegeGranted,
                objectType: 'practitioner_privilege',
                objectRef: (string) $stored->id,
                after: [
                    'credential_id' => $stored->credentialId,
                    'procedure_code' => $stored->procedureCode,
                    'basis' => $stored->basis->value,
                    'effective_from' => $stored->startsOn(),
                    'effective_to' => $stored->endsOn(),
                ],
                occurredAtUtc: $when,
            ));

            return $stored;
        });
    }

    /**
     * @param array<string, string> $errors
     *
     * @return array<string, string>
     */
    private function window(string $from, string $to, array $errors): array
    {
        if ($from !== '' && !$this->isIsoDate($from)) {
            $errors['effective_from'] = 'The start date must be a real date, as YYYY-MM-DD.';
        }

        if ($to !== '' && !$this->isIsoDate($to)) {
            $errors['effective_to'] = 'The end date must be a real date, as YYYY-MM-DD, or empty.';
        }

        if ($from !== '' && $to !== '' && $this->isIsoDate($from) && $this->isIsoDate($to) && $to < $from) {
            $errors['effective_to'] = 'A privilege cannot end before the day it starts.';
        }

        return $errors;
    }

    private function isIsoDate(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
