<?php

declare(strict_types=1);

namespace App\Domain;

use App\Validation\Validator;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Submit an elective referral.
 *
 * The command, not the controller, decides what a valid submission is, who may
 * make one and which state it starts in. That ordering is what WFS 15 asks for:
 * authenticate, authorise, validate, then write — with the history row written in
 * the same transaction as the referral.
 *
 * What this deliberately does not do:
 * - It does not create an appointment, a time or a resource (WF-01).
 * - It does not decide anything clinically; submission always lands on
 *   `submitted`, and only a radiologist can move it out of review (WF-02, WF-03).
 * - It does not accept a patient-requested priority as a clinical priority; the
 *   `priority` flag is the referrer's own request, and the clinical priority is
 *   set later by clinical review (WF-11).
 */
final class SubmitElectiveReferral
{
    private const REFERENCE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const SCOPE = 'referral.submit';

    public function __construct(
        private readonly ReferralRepository $repository,
        private readonly Validator $validator,
        private readonly IdempotencyStore $idempotency,
        private readonly PhysicianProfileRepository $physicians,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AuthorisationException|ValidationException|ReferralTransitionException
     */
    public function execute(
        Actor $actor,
        array $input,
        ?string $nowUtc = null,
        ?string $idempotencyKey = null,
    ): Referral {
        if (!$actor->can(Permission::ReferralSubmit)) {
            throw new AuthorisationException($actor->role, Permission::ReferralSubmit);
        }

        $data = [
            'patient_name' => $this->text($input, 'patient_name'),
            'patient_phone' => $this->text($input, 'patient_phone'),
            'patient_dob' => $this->text($input, 'patient_dob'),
            'guardian_name' => $this->text($input, 'guardian_name'),
            'guardian_phone' => $this->text($input, 'guardian_phone'),
            'guardian_relationship' => $this->text($input, 'guardian_relationship'),
            'procedure_code' => $this->text($input, 'procedure_code'),
            'requested_urgency' => $this->text($input, 'requested_urgency'),
            'clinical_summary' => $this->text($input, 'clinical_summary'),
            'target_site' => $this->text($input, 'target_site'),
            'referring_physician_name' => $this->text($input, 'referring_physician_name'),
            'referring_facility' => $this->text($input, 'referring_facility'),
            'referring_physician_phone' => $this->text($input, 'referring_physician_phone'),
        ];

        $errors = $this->validator->validate($data, [
            'patient_name' => 'required|min:2|max:120',
            'patient_phone' => 'required|phone',
            'patient_dob' => 'max:10',
            'guardian_name' => 'max:120',
            'guardian_phone' => 'max:32',
            'guardian_relationship' => 'max:40',
            'procedure_code' => 'required|max:40',
            'requested_urgency' => 'required|in:' . implode(',', Priority::requestableValues()),
            'clinical_summary' => 'required|min:10|max:4000',
            'target_site' => 'max:120',
            'referring_physician_name' => 'max:120',
            'referring_facility' => 'max:160',
            'referring_physician_phone' => 'max:32',
        ]);

        $dob = $this->date($data['patient_dob']);
        if ($data['patient_dob'] !== '' && $dob === null) {
            $errors['patient_dob'] = 'Date of birth must be a real date in the past, as YYYY-MM-DD.';
        }

        if (
            $data['guardian_phone'] !== ''
            && preg_match('/^\+?[0-9][0-9 ()\-]{6,19}$/', $data['guardian_phone']) !== 1
        ) {
            $errors['guardian_phone'] = 'Guardian phone does not look like a phone number.';
        }

        // §6.3: guardian details are one fact in three parts. A name with no way to
        // reach the guardian, or one that does not say how they stand to the patient,
        // is a record nobody can act on — and the third part is what separates "a
        // relative brought the child" from "this person may decide for the child".
        $guardianParts = [
            'guardian_name' => $data['guardian_name'],
            'guardian_phone' => $data['guardian_phone'],
            'guardian_relationship' => $data['guardian_relationship'],
        ];
        $guardianGiven = array_filter($guardianParts, static fn (string $part): bool => $part !== '');

        if ($guardianGiven !== [] && count($guardianGiven) < count($guardianParts)) {
            foreach ($guardianParts as $field => $value) {
                if ($value === '') {
                    $errors[$field] = 'Guardian name, contact number and relationship are recorded '
                        . 'together; one without the others cannot be acted on.';
                }
            }
        }

        // A submission for a child is made by somebody acting for them, and §6.2
        // forbids inferring who that is. Without a date of birth the age is unknown,
        // and nothing at all is inferred from its absence — the rule simply does not
        // fire. Naming a guardian here is not the same as accepting their authority:
        // that stays unverified until a member of staff records otherwise (PAT-013).
        if ($dob !== null && $this->isMinor($dob) && $guardianGiven === []) {
            $errors['guardian_name'] = 'A patient under 18 needs a parent or guardian named, with a '
                . 'contact number and their relationship to the patient.';
        }

        if (
            $data['referring_physician_phone'] !== ''
            && preg_match('/^\+?[0-9][0-9 ()\-]{6,19}$/', $data['referring_physician_phone']) !== 1
        ) {
            $errors['referring_physician_phone'] = 'Referring physician phone does not look like a phone number.';
        }

        if (
            $data['procedure_code'] !== ''
            && preg_match('/^[a-z][a-z0-9_]{2,39}$/', $data['procedure_code']) !== 1
        ) {
            $errors['procedure_code'] = 'Procedure code must be lower case letters, digits and underscores.';
        }

        if (isset($errors['requested_urgency'])) {
            // The validator says "not in the list", which tells a frightened parent
            // nothing. An approved category that the form does not offer is the
            // likely cause — "urgent" or the retired "priority" — so say what is
            // allowed and where urgency actually belongs.
            $errors['requested_urgency'] = 'Choose routine or expedited review. '
                . 'Urgent cases are coordinated by telephone, not requested here.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $submittedOnBehalfOf = $this->submittedOnBehalfOf($actor, $data['guardian_name'] !== '');

        // A physician submits under their verified profile, and an unverified one
        // submits nothing (AUTH-02). Patients and staff have no profile to check.
        $profile = null;

        if ($actor->role === Role::Physician) {
            $profile = $this->physicians->findByUserId($actor->userId);

            if (!(new ReferralAccessPolicy())->maySubmitAsPhysician($profile)) {
                throw AuthorisationException::forObject(
                    $actor->role,
                    Permission::ReferralSubmit,
                    'Your referring profile is not verified yet.',
                );
            }
        }

        $createdAtUtc = $nowUtc ?? gmdate('Y-m-d H:i:s');

        // Claimed after validation, so a rejected form does not burn the key: the
        // patient corrects the mistake and resubmits with the same token.
        if ($idempotencyKey !== null) {
            $claim = $this->idempotency->claim(
                self::SCOPE,
                $idempotencyKey,
                Idempotency::fingerprint($data),
                $actor,
                $createdAtUtc,
            );

            if ($claim->state === IdempotencyState::Conflict) {
                throw new IdempotencyConflictException(self::SCOPE, $claim->reason);
            }

            if ($claim->state === IdempotencyState::Replay && $claim->reference !== null) {
                $existing = $this->repository->findByReference($claim->reference);

                if ($existing === null) {
                    throw new IdempotencyConflictException(
                        self::SCOPE,
                        'The recorded request could not be found.',
                    );
                }

                return $existing;
            }
        }

        $referral = new Referral(
            id: 0,
            reference: $this->reference(),
            status: ReferralStatus::Submitted,
            version: 1,
            patientName: $data['patient_name'],
            patientPhone: $data['patient_phone'],
            patientDob: $dob,
            guardianName: $this->nullable($data['guardian_name']),
            guardianPhone: $this->nullable($data['guardian_phone']),
            guardianRelationship: $this->nullable($data['guardian_relationship']),
            procedureCode: $data['procedure_code'],
            requestedUrgency: $data['requested_urgency'],
            clinicalSummary: $this->nullable($data['clinical_summary']),
            targetSite: $this->nullable($data['target_site']),
            referringPhysicianName: $this->nullable($data['referring_physician_name']),
            referringFacility: $this->nullable($data['referring_facility']),
            referringPhysicianPhone: $this->nullable($data['referring_physician_phone']),
            submittedByUserId: $actor->userId,
            submittedOnBehalfOf: $submittedOnBehalfOf,
            createdAtUtc: $createdAtUtc,
            referringPhysicianProfileId: $profile?->id,
        );

        // The confirmation is enqueued in the same transaction as the referral and
        // names only the reference; delivery happens later and its failure must not
        // undo a submission that has already been accepted (WF-14).
        $message = new OutboundMessage(
            channel: NotificationChannel::Sms,
            template: NotificationTemplate::ReferralReceived,
            recipient: $data['guardian_phone'] !== '' && $data['guardian_phone'] !== $data['patient_phone']
                ? $data['guardian_phone']
                : $data['patient_phone'],
            payload: [
                'reference' => $referral->reference,
                'procedure' => $referral->procedureCode,
            ],
        );

        $stored = $this->repository->create($referral, $actor, $createdAtUtc, [$message]);

        if ($idempotencyKey !== null) {
            $this->idempotency->complete(self::SCOPE, $idempotencyKey, $stored->reference, $createdAtUtc);
        }

        return $stored;
    }

    /**
     * A physician submits as a referrer; staff submit on a caller's behalf, and the
     * guardian fields say whether that caller was the patient or someone acting for
     * them.
     */
    private function submittedOnBehalfOf(Actor $actor, bool $hasGuardian): string
    {
        return match ($actor->role) {
            Role::Physician => 'referrer',
            // A patient cannot submit (ADR-016), so the record says two true things:
            // a member of staff entered it, and whose authority it was entered under.
            // Guardian details are what distinguish the two.
            default => $hasGuardian ? 'guardian' : 'staff',
        };
    }

    private function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        if ($parsed > new DateTimeImmutable('today', new DateTimeZone('UTC'))) {
            return null;
        }

        return $value;
    }

    /**
     * Whether a date of birth falls on the minor side of the age of majority.
     *
     * 18 is the age of majority in this jurisdiction. The boundary decides one thing
     * only: whether somebody acting for the patient has to be named. It says nothing
     * about capacity or authority — those are assessed by a person (PAT-013), and
     * treating an age check as an authority check is the inference §6.2 forbids.
     */
    private function isMinor(string $dateOfBirth): bool
    {
        $born = DateTimeImmutable::createFromFormat('!Y-m-d', $dateOfBirth, new DateTimeZone('UTC'));

        if ($born === false) {
            return false;
        }

        return $born->diff(new DateTimeImmutable('today', new DateTimeZone('UTC')))->y < 18;
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private function reference(): string
    {
        $suffix = '';
        for ($i = 0; $i < 6; $i++) {
            $suffix .= self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)];
        }

        return 'RF-' . gmdate('ymd') . '-' . $suffix;
    }
}
