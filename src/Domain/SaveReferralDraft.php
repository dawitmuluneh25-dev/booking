<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Keep an unfinished referral.
 *
 * A draft is validated for shape, not for completeness: the point is to let a
 * referrer stop halfway and come back, so the only thing refused outright is a
 * procedure that is not in the catalogue. Everything a submitted referral must
 * have is checked at the moment of submission instead (`SubmitDraft`), which is
 * the only moment it matters.
 */
final class SaveReferralDraft
{
    public function __construct(
        private readonly ReferralRepository $referrals,
        private readonly ProcedureRepository $procedures,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AuthorisationException|ValidationException
     */
    public function execute(Actor $actor, array $input, ?string $nowUtc = null): Referral
    {
        if (!$actor->can(Permission::ReferralSubmit)) {
            throw new AuthorisationException($actor->role, Permission::ReferralSubmit);
        }

        $code = $this->text($input, 'procedure_code');

        if ($code !== '' && $this->procedures->find($code) === null) {
            throw new ValidationException(['procedure_code' => 'Choose a procedure from the list.']);
        }

        // A draft took whatever urgency it was handed, and the column used to
        // accept it. Now that the vocabulary is the approved one (§13.1, A-08),
        // an unvalidated value reaches a CHECK constraint and becomes a 500 on a
        // page a patient is half-way through filling in. Refuse it here, where
        // the answer can be a message.
        $urgency = $this->text($input, 'requested_urgency');

        if ($urgency !== '' && Priority::tryFrom($urgency)?->isRequestable() !== true) {
            throw new ValidationException([
                'requested_urgency' => 'Choose routine or expedited review. '
                    . 'Urgent cases are coordinated by telephone, not requested here.',
            ]);
        }

        $referral = new Referral(
            id: 0,
            reference: 'RF-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(3))),
            status: ReferralStatus::Draft,
            version: 1,
            patientName: $this->text($input, 'patient_name'),
            patientPhone: $this->text($input, 'patient_phone'),
            patientDob: $this->date($this->text($input, 'patient_dob')),
            guardianName: $this->nullable($this->text($input, 'guardian_name')),
            guardianPhone: $this->nullable($this->text($input, 'guardian_phone')),
            guardianRelationship: $this->nullable($this->text($input, 'guardian_relationship')),
            procedureCode: $code,
            requestedUrgency: $urgency === '' ? 'routine' : $urgency,
            clinicalSummary: $this->nullable($this->text($input, 'clinical_summary')),
            targetSite: $this->nullable($this->text($input, 'target_site')),
            referringPhysicianName: $this->nullable($this->text($input, 'referring_physician_name')),
            referringFacility: $this->nullable($this->text($input, 'referring_facility')),
            referringPhysicianPhone: $this->nullable($this->text($input, 'referring_physician_phone')),
            submittedByUserId: $actor->userId,
            submittedOnBehalfOf: 'self',
            createdAtUtc: $nowUtc ?? gmdate('Y-m-d H:i:s'),
            referringPhysicianProfileId: null,
        );

        return $this->referrals->create($referral, $actor, $nowUtc ?? gmdate('Y-m-d H:i:s'));
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /** A partial draft keeps an unreadable date as "not given" rather than guessing. */
    private function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed === false ? null : $parsed->format('Y-m-d');
    }
}
