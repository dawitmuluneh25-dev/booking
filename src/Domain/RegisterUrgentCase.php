<?php

declare(strict_types=1);

namespace App\Domain;

use App\Validation\Validator;

/**
 * Register a suspected intussusception case on the urgent pathway.
 *
 * There is no payment step, no referral approval and no scheduling here (WF-07):
 * the case exists so that a child can be coordinated with a receiving hospital
 * now, and nothing about it waits on an invoice or a booking.
 *
 * Age is required in one form or another. Intussusception is a paediatric
 * presentation and "child, unknown age" is not enough information to coordinate,
 * so the command refuses rather than storing a case nobody can act on.
 */
final class RegisterUrgentCase
{
    /** The workflow this aggregate exists for; callers may not substitute another. */
    public const PROCEDURE_CODE = 'intussusception_reduction';

    private const REFERENCE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly UrgentCaseRepository $repository,
        private readonly Validator $validator,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AuthorisationException the actor may not submit requests at all
     * @throws ValidationException    the case is not complete enough to coordinate
     */
    public function execute(Actor $actor, array $input, ?string $nowUtc = null): UrgentCase
    {
        if (!$actor->can(Permission::ReferralSubmit)) {
            throw new AuthorisationException($actor->role, Permission::ReferralSubmit);
        }

        $data = [
            'patient_name' => $this->text($input, 'patient_name'),
            'patient_dob' => $this->text($input, 'patient_dob'),
            'patient_age_months' => $this->text($input, 'patient_age_months'),
            'guardian_name' => $this->text($input, 'guardian_name'),
            'guardian_phone' => $this->text($input, 'guardian_phone'),
            'guardian_relationship' => $this->text($input, 'guardian_relationship'),
            'referring_physician_name' => $this->text($input, 'referring_physician_name'),
            'referring_facility' => $this->text($input, 'referring_facility'),
            'referring_physician_phone' => $this->text($input, 'referring_physician_phone'),
            'receiving_hospital_name' => $this->text($input, 'receiving_hospital_name'),
            'clinical_summary' => $this->text($input, 'clinical_summary'),
            'imaging_summary' => $this->text($input, 'imaging_summary'),
        ];

        $errors = $this->validator->validate($data, [
            'patient_name' => 'required|min:2|max:120',
            'patient_dob' => 'max:10',
            'patient_age_months' => 'max:3',
            'guardian_name' => 'max:120',
            'guardian_phone' => 'required|phone',
            'guardian_relationship' => 'max:40',
            'referring_physician_name' => 'max:120',
            'referring_facility' => 'max:160',
            'referring_physician_phone' => 'max:32',
            'receiving_hospital_name' => 'max:160',
            'clinical_summary' => 'required|min:10|max:4000',
            'imaging_summary' => 'max:4000',
        ]);

        $dob = $this->date($data['patient_dob']);
        if ($data['patient_dob'] !== '' && $dob === null) {
            $errors['patient_dob'] = 'Date of birth must be a real date in the past, as YYYY-MM-DD.';
        }

        $ageMonths = null;
        if ($data['patient_age_months'] !== '') {
            if (preg_match('/^[0-9]{1,3}$/', $data['patient_age_months']) !== 1) {
                $errors['patient_age_months'] = 'Age in months must be a whole number.';
            } else {
                $ageMonths = (int) $data['patient_age_months'];
            }
        }

        if ($dob === null && $ageMonths === null) {
            $errors['patient_age_months'] = 'Record the child\'s date of birth or age in months.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $createdAtUtc = $nowUtc ?? gmdate('Y-m-d H:i:s');

        $case = new UrgentCase(
            id: 0,
            reference: $this->reference(),
            urgency: UrgentStatus::Received,
            coordination: UrgentCoordination::Unacknowledged,
            version: 1,
            procedureCode: self::PROCEDURE_CODE,
            patientName: $data['patient_name'],
            patientDob: $dob,
            patientAgeMonths: $ageMonths,
            guardianName: $this->nullable($data['guardian_name']),
            guardianPhone: $data['guardian_phone'],
            guardianRelationship: $this->nullable($data['guardian_relationship']),
            referringUserId: $actor->role === Role::Physician ? $actor->userId : null,
            referringPhysicianName: $this->nullable($data['referring_physician_name']),
            referringFacility: $this->nullable($data['referring_facility']),
            referringPhysicianPhone: $this->nullable($data['referring_physician_phone']),
            receivingHospitalName: $this->nullable($data['receiving_hospital_name']),
            clinicalSummary: $data['clinical_summary'],
            imagingSummary: $this->nullable($data['imaging_summary']),
            receivedAtUtc: $createdAtUtc,
            createdAtUtc: $createdAtUtc,
        );

        return $this->repository->create($case, $actor, $createdAtUtc);
    }

    private function reference(): string
    {
        $suffix = '';
        $max = strlen(self::REFERENCE_ALPHABET) - 1;

        for ($i = 0; $i < 6; $i++) {
            $suffix .= self::REFERENCE_ALPHABET[random_int(0, $max)];
        }

        return 'UR-' . gmdate('ymd') . '-' . $suffix;
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
