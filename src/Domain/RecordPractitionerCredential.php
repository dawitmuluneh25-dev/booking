<?php

declare(strict_types=1);

namespace App\Domain;

use App\Validation\Validator;
use DateTimeImmutable;

/**
 * Record a verification of a practitioner's credential (Decision 6, AC-009).
 *
 * The act of recording *is* the verification, and the row says who asserted it, on
 * what evidence and for which window. Every field the Product Owner's decision
 * lists is either stored here or stored on the privileges beneath it; nothing is
 * inferred from the account's role.
 *
 * Two refusals worth naming:
 *
 * - the subject must be able to hold `clinical.approve` at all. A verification for
 *   a receptionist would be a record that promises something the account cannot do,
 *   and would make the register look fuller than the authority it represents;
 * - the window has to be coherent. An end date before the start date is not a
 *   verification with a typo, it is a verification that authorises nothing, and
 *   storing it would leave the reader to work that out.
 */
final class RecordPractitionerCredential
{
    public function __construct(
        private readonly PractitionerCredentialRepository $credentials,
        private readonly UserRepository $users,
        private readonly Validator $validator,
        private readonly Transaction $transaction,
        private readonly AuditTrail $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AuthorisationException    the actor may not record verifications
     * @throws ValidationException       the record is incomplete or the subject cannot approve
     */
    public function execute(Actor $actor, int $userId, array $input, ?string $nowUtc = null): PractitionerCredential
    {
        if (!$actor->can(Permission::ClinicalCredentialManage)) {
            throw new AuthorisationException($actor->role, Permission::ClinicalCredentialManage);
        }

        $subject = $this->users->findById($userId);

        if ($subject === null) {
            throw new ValidationException([
                'practitioner_id' => 'Choose the account this verification belongs to.',
            ]);
        }

        if (!$subject->role->can(Permission::ClinicalApprove)) {
            throw new ValidationException([
                'practitioner_id' => $subject->name . ' does not hold clinical approval authority, so a '
                    . 'credential for them would authorise nothing. Record the verification against '
                    . 'an account that can approve a procedure.',
            ]);
        }

        $data = [
            'credential_type' => $this->text($input, 'credential_type'),
            'registration_number' => $this->text($input, 'registration_number'),
            'qualifications' => $this->text($input, 'qualifications'),
            'evidence_reference' => $this->text($input, 'evidence_reference'),
            'institutional_privilege_status' => $this->text($input, 'institutional_privilege_status'),
            'supervision_restriction' => $this->text($input, 'supervision_restriction'),
            'effective_from' => $this->text($input, 'effective_from'),
            'expires_on' => $this->text($input, 'expires_on'),
        ];

        $errors = $this->validator->validate($data, [
            'credential_type' => 'required|min:2|max:60',
            'registration_number' => 'required|min:2|max:60',
            'qualifications' => 'max:500',
            'evidence_reference' => 'required|min:5|max:200',
            'institutional_privilege_status' => 'max:60',
            'supervision_restriction' => 'max:500',
            'effective_from' => 'required',
            'expires_on' => 'max:10',
        ]);

        $errors = $this->window($data['effective_from'], $data['expires_on'], $errors);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $when = $nowUtc ?? gmdate('Y-m-d H:i:s');

        $credential = new PractitionerCredential(
            id: 0,
            userId: $subject->id,
            credentialType: $data['credential_type'],
            registrationNumber: $data['registration_number'],
            qualifications: $this->nullable($data['qualifications']),
            evidenceReference: $data['evidence_reference'],
            institutionalPrivilegeStatus: $this->nullable($data['institutional_privilege_status']),
            supervisionRestriction: $this->nullable($data['supervision_restriction']),
            status: PractitionerCredentialStatus::Verified,
            verifiedByUserId: $actor->userId,
            verifiedAtUtc: $when,
            effectiveFrom: $data['effective_from'],
            expiresOn: $this->nullable($data['expires_on']),
            createdAtUtc: $when,
        );

        return $this->transaction->run(function () use ($actor, $credential, $when): PractitionerCredential {
            $stored = $this->credentials->create($credential);

            $this->audit->record(AuditEntry::byActor(
                actor: $actor,
                action: AuditAction::PractitionerCredentialRecorded,
                objectType: 'practitioner_credential',
                objectRef: (string) $stored->id,
                after: [
                    'user_id' => $stored->userId,
                    'credential_type' => $stored->credentialType,
                    'status' => $stored->status->value,
                    'effective_from' => $stored->startsOn(),
                    'expires_on' => $stored->expiresOnDay(),
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
            $errors['effective_from'] = 'The effective date must be a real date, as YYYY-MM-DD.';
        }

        if ($to !== '' && !$this->isIsoDate($to)) {
            $errors['expires_on'] = 'The expiry date must be a real date, as YYYY-MM-DD, or empty.';
        }

        if ($from !== '' && $to !== '' && $this->isIsoDate($from) && $this->isIsoDate($to) && $to < $from) {
            $errors['expires_on'] = 'The credential cannot expire before it takes effect.';
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
