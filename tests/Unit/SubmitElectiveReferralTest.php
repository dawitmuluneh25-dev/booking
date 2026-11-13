<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\PhysicianProfile;
use App\Domain\PhysicianStatus;
use App\Domain\IdempotencyConflictException;
use App\Domain\ReferralStatus;
use App\Domain\Role;
use App\Domain\SubmitElectiveReferral;
use App\Domain\ValidationException;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryIdempotencyStore;
use Tests\Support\InMemoryPhysicianProfileRepository;
use Tests\Support\InMemoryReferralRepository;

final class SubmitElectiveReferralTest extends TestCase
{
    private const NOW = '2026-11-08 09:00:00';

    private InMemoryReferralRepository $repository;

    private InMemoryIdempotencyStore $idempotency;

    private InMemoryPhysicianProfileRepository $physicians;

    private SubmitElectiveReferral $command;

    protected function setUp(): void
    {
        $this->repository = new InMemoryReferralRepository();
        $this->idempotency = new InMemoryIdempotencyStore();
        $this->physicians = new InMemoryPhysicianProfileRepository();
        $this->command = new SubmitElectiveReferral(
            $this->repository,
            new Validator(),
            $this->idempotency,
            $this->physicians,
        );
    }

    public function testPatientSubmissionStartsAtSubmittedAndNeverAtApproved(): void
    {
        $referral = $this->command->execute(
            new Actor(7, Role::Staff),
            $this->validInput(),
            self::NOW,
        );

        self::assertGreaterThan(0, $referral->id);
        self::assertSame(ReferralStatus::Submitted, $referral->status);
        self::assertSame(1, $referral->version);
        // A patient cannot submit (ADR-016); staff entered this on their behalf.
        self::assertSame('staff', $referral->submittedOnBehalfOf);
        self::assertSame(7, $referral->submittedByUserId);
        self::assertSame(self::NOW, $referral->createdAtUtc);
    }

    public function testSubmissionWritesAnOpeningHistoryRow(): void
    {
        $referral = $this->command->execute(new Actor(7, Role::Staff), $this->validInput(), self::NOW);

        $history = $this->repository->historyFor($referral->id);

        self::assertCount(1, $history);
        self::assertNull($history[0]['from']);
        self::assertSame('submitted', $history[0]['to']);
        self::assertSame('staff', $history[0]['actor_role']);
    }

    public function testReferenceIsGeneratedRatherThanAcceptedFromInput(): void
    {
        $referral = $this->command->execute(
            new Actor(7, Role::Staff),
            $this->validInput() + ['reference' => 'ATTACKER-CHOSEN'],
            self::NOW,
        );

        self::assertStringStartsWith('RF-', $referral->reference);
        self::assertNotSame('ATTACKER-CHOSEN', $referral->reference);
        self::assertSame($referral, $this->repository->findByReference($referral->reference));
    }

    public function testGuardianSubmissionIsRecordedAsGuardian(): void
    {
        $referral = $this->command->execute(
            new Actor(9, Role::Staff),
            $this->validInput() + [
                'guardian_name' => 'Meron Tesfaye',
                'guardian_phone' => '+251911000111',
                'guardian_relationship' => 'Mother',
            ],
            self::NOW,
        );

        self::assertSame('guardian', $referral->submittedOnBehalfOf);
        self::assertSame('Meron Tesfaye', $referral->guardianName);
    }

    public function testTheActorIsTakenFromTheSessionNotTheSubmittedForm(): void
    {
        // AUTH-09 / AUTH-10: a form may not name its own actor or role. Both come
        // from the authenticated Actor, so smuggled fields are simply not read.
        $referral = $this->command->execute(
            new Actor(7, Role::Staff),
            $this->validInput() + [
                'submitted_by_user_id' => '999',
                'actor_id' => '999',
                'role' => 'admin',
                'submitted_on_behalf_of' => 'staff',
            ],
            self::NOW,
        );

        self::assertSame(7, $referral->submittedByUserId);
        // A patient cannot submit (ADR-016); staff entered this on their behalf.
        self::assertSame('staff', $referral->submittedOnBehalfOf);
    }

    public function testAGuardianSubmissionIsRecordedAsSuchNotAsTheChild(): void
    {
        $referral = $this->command->execute(
            new Actor(7, Role::Staff),
            // A guardian is three facts, not one: name, a way to reach them, and how
            // they stand to the patient. A name alone is refused as partial.
            $this->validInput() + [
                'guardian_name' => 'Tesfaye Alemu',
                'guardian_phone' => '+251911222555',
                'guardian_relationship' => 'father',
            ],
            self::NOW,
        );

        self::assertSame('guardian', $referral->submittedOnBehalfOf);
        self::assertSame(7, $referral->submittedByUserId);
    }

    public function testAnUnverifiedPhysicianCannotSubmit(): void
    {
        // A pending profile is not a referrer yet (AUTH-02). The account exists and
        // can sign in; the authority to send clinical correspondence is separate.
        $this->physicians->create($this->profile(PhysicianStatus::Pending), self::NOW);

        $this->expectException(AuthorisationException::class);
        $this->expectExceptionMessageMatches('/not verified/i');

        $this->command->execute(new Actor(11, Role::Physician), $this->validInput(), self::NOW);
    }

    public function testASuspendedPhysicianCannotSubmit(): void
    {
        $this->physicians->create($this->profile(PhysicianStatus::Suspended), self::NOW);

        $this->expectException(AuthorisationException::class);
        $this->command->execute(new Actor(11, Role::Physician), $this->validInput(), self::NOW);
    }

    public function testAVerifiedPhysicianSubmitsUnderTheirOwnProfile(): void
    {
        $profile = $this->physicians->create($this->profile(PhysicianStatus::Verified), self::NOW);

        $referral = $this->command->execute(
            new Actor(11, Role::Physician),
            $this->validInput() + [
                'referring_physician_name' => 'Dr Hana Bekele',
                'referring_facility' => 'Yekatit 12 Hospital',
                'referring_physician_phone' => '+251911000222',
            ],
            self::NOW,
        );

        self::assertSame('referrer', $referral->submittedOnBehalfOf);
        self::assertSame('Yekatit 12 Hospital', $referral->referringFacility);
        self::assertSame($profile->id, $referral->referringPhysicianProfileId);
    }

    public function testAPatientSubmissionCarriesNoPhysicianProfile(): void
    {
        $referral = $this->command->execute(new Actor(7, Role::Staff), $this->validInput(), self::NOW);

        self::assertNull($referral->referringPhysicianProfileId);
    }

    private function profile(PhysicianStatus $status): PhysicianProfile
    {
        return new PhysicianProfile(
            id: 0,
            userId: 11,
            practiceNumber: 'ET-MD-4821',
            institution: 'Yekatit 12 Hospital',
            specialty: 'Internal medicine',
            status: $status,
            verifiedByUserId: null,
            verifiedAtUtc: null,
            reviewNote: null,
            createdAtUtc: self::NOW,
        );
    }

    public function testRadiologistMayNotSubmitBecauseTheyMustNotReferToThemselves(): void
    {
        $this->expectException(AuthorisationException::class);

        $this->command->execute(new Actor(3, Role::Radiologist), $this->validInput(), self::NOW);
    }

    public function testAdministratorCannotSubmitAClinicalRequest(): void
    {
        // Administrative privilege is not clinical privilege (SEC 2.5, WF-03).
        $this->expectException(AuthorisationException::class);

        $this->command->execute(new Actor(1, Role::Admin), $this->validInput(), self::NOW);
    }

    public function testClinicalSummaryTooShortIsRefused(): void
    {
        try {
            $this->command->execute(
                new Actor(7, Role::Staff),
                ['clinical_summary' => 'pain'] + $this->validInput(),
                self::NOW,
            );
            self::fail('A two-word summary was accepted as a clinical summary.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('clinical_summary', $exception->errors);
        }

        self::assertSame(0, $this->repository->count());
    }

    public function testFutureDateOfBirthIsRefused(): void
    {
        try {
            $this->command->execute(
                new Actor(7, Role::Staff),
                ['patient_dob' => '2099-01-01'] + $this->validInput(),
                self::NOW,
            );
            self::fail('A date of birth in the future was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('patient_dob', $exception->errors);
        }
    }

    public function testImpossibleDateIsRefusedRatherThanNormalised(): void
    {
        try {
            $this->command->execute(
                new Actor(7, Role::Staff),
                ['patient_dob' => '2026-02-31'] + $this->validInput(),
                self::NOW,
            );
            self::fail('31 February was accepted and silently rolled over.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('patient_dob', $exception->errors);
        }
    }

    public function testPatientRequestedUrgencyIsNotAClinicalPriority(): void
    {
        $referral = $this->command->execute(
            new Actor(7, Role::Staff),
            ['requested_urgency' => 'expedited'] + $this->validInput(),
            self::NOW,
        );

        // The referrer's own request is stored, but submission produces no clinical
        // decision and no clinical priority (WF-11).
        self::assertSame('expedited', $referral->requestedUrgency);
        self::assertSame(ReferralStatus::Submitted, $referral->status);
        self::assertCount(1, $this->repository->historyFor($referral->id));
    }

    public function testUnacceptedUrgencyIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $this->command->execute(
            new Actor(7, Role::Staff),
            ['requested_urgency' => 'emergency'] + $this->validInput(),
            self::NOW,
        );
    }

    /**
     * `priority` was the catalogue's own word, retired by HR-IR-CLIN-R1-001 §13.1
     * (A-08). A form bookmarked before the migration — or a hand-crafted POST —
     * must be told what the approved categories are, not handed a CHECK violation.
     */
    public function testTheRetiredUrgencyWordIsRefusedWithTheApprovedVocabulary(): void
    {
        try {
            $this->command->execute(
                new Actor(7, Role::Staff),
                ['requested_urgency' => 'priority'] + $this->validInput(),
                self::NOW,
            );
            self::fail('The retired category was accepted after the vocabulary changed.');
        } catch (ValidationException $exception) {
            $message = $exception->errors['requested_urgency'] ?? '';

            self::assertStringContainsString('routine or expedited', $message);
            self::assertStringNotContainsString('in:', $message);
        }
    }

    /**
     * The clinician-only category is not requestable. It is the one priority that
     * sends a case outside the ordinary queue, so a referrer must not be able to
     * choose it by posting the value the clinical form writes.
     */
    public function testUrgentDirectCoordinationCannotBeRequestedByASubmitter(): void
    {
        try {
            $this->command->execute(
                new Actor(7, Role::Staff),
                ['requested_urgency' => 'urgent_direct_coordination'] + $this->validInput(),
                self::NOW,
            );
            self::fail('A submitter assigned a clinical priority.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('requested_urgency', $exception->errors);
        }
    }

    public function testProcedureCodeShapeIsChecked(): void
    {
        $this->expectException(ValidationException::class);

        $this->command->execute(
            new Actor(7, Role::Staff),
            ['procedure_code' => 'Fluoroscopic Biopsy; DROP TABLE'] + $this->validInput(),
            self::NOW,
        );
    }

    public function testTheSameTokenReturnsTheFirstReferralInsteadOfASecond(): void
    {
        $actor = new Actor(7, Role::Staff);
        $token = 'submission-token-0001';

        $first = $this->command->execute($actor, $this->validInput(), self::NOW, $token);
        $second = $this->command->execute($actor, $this->validInput(), self::NOW, $token);

        self::assertSame($first->id, $second->id);
        self::assertSame($first->reference, $second->reference);
        self::assertSame(1, $this->repository->count());
        self::assertCount(1, $this->repository->historyFor($first->id));
    }

    public function testTheSameTokenWithDifferentDetailsIsRefused(): void
    {
        $actor = new Actor(7, Role::Staff);
        $token = 'submission-token-0002';

        $this->command->execute($actor, $this->validInput(), self::NOW, $token);

        $this->expectException(IdempotencyConflictException::class);
        $this->command->execute(
            $actor,
            ['patient_name' => 'Someone Else'] + $this->validInput(),
            self::NOW,
            $token,
        );
    }

    public function testARejectedSubmissionDoesNotBurnTheToken(): void
    {
        $actor = new Actor(7, Role::Staff);
        $token = 'submission-token-0003';

        try {
            $this->command->execute($actor, ['patient_name' => ''] + $this->validInput(), self::NOW, $token);
        } catch (ValidationException) {
            // The patient corrects the form and submits again with the same token.
        }

        $referral = $this->command->execute($actor, $this->validInput(), self::NOW, $token);

        self::assertSame(ReferralStatus::Submitted, $referral->status);
        self::assertSame(1, $this->repository->count());
    }

    public function testASubmissionForAChildNeedsSomebodyNamedToActForThem(): void
    {
        try {
            $this->command->execute(
                new Actor(7, Role::Staff),
                ['patient_dob' => '2018-06-01'] + $this->validInput(),
                self::NOW,
            );
            self::fail('A referral for a child was accepted with nobody named to act for them.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('guardian_name', $exception->errors);
        }
    }

    public function testTheSameChildIsAcceptedOnceTheGuardianIsNamedProperly(): void
    {
        $referral = $this->command->execute(
            new Actor(7, Role::Staff),
            [
                'patient_dob' => '2018-06-01',
                'guardian_name' => 'Meseret Alemu',
                'guardian_phone' => '+251911222444',
                'guardian_relationship' => 'mother',
            ] + $this->validInput(),
            self::NOW,
        );

        self::assertSame('Meseret Alemu', $referral->guardianName);
        // Naming a guardian is not accepting their authority: that needs a person to
        // verify it (PAT-013), and this command deliberately records none.
        self::assertSame('mother', $referral->guardianRelationship);
    }

    public function testGuardianDetailsAreOneFactAndAPartialSetIsRefused(): void
    {
        try {
            $this->command->execute(
                new Actor(7, Role::Staff),
                ['guardian_name' => 'Meseret Alemu'] + $this->validInput(),
                self::NOW,
            );
            self::fail('A guardian name with no contact number or relationship was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('guardian_phone', $exception->errors);
            self::assertArrayHasKey('guardian_relationship', $exception->errors);
        }
    }

    public function testWithoutADateOfBirthNoGuardianIsDemandedBecauseNothingIsInferred(): void
    {
        $input = $this->validInput();
        unset($input['patient_dob']);

        $referral = $this->command->execute(new Actor(7, Role::Staff), $input, self::NOW);

        self::assertNull($referral->guardianName);
    }

    public function testAnAgeIsStoredWithItsUnitAndItsSourceTogether(): void
    {
        $referral = $this->command->execute(
            new Actor(7, Role::Staff),
            [
                'patient_dob' => '',
                'patient_age_value' => '9',
                'patient_age_unit' => 'months',
                'patient_age_source' => 'parent_or_guardian',
            ] + $this->validInput(),
            self::NOW,
        );

        self::assertSame(9, $referral->patientAgeValue);
        self::assertSame('months', $referral->patientAgeUnit);
        self::assertNotNull($referral->patientAge());
        self::assertSame('9 months, stated by a parent or guardian.', $referral->patientAge()->describeWithSource());
    }

    public function testAnAgeWithoutAUnitIsRefusedBecauseThreeIsNotAnAge(): void
    {
        try {
            $this->command->execute(
                new Actor(7, Role::Staff),
                ['patient_age_value' => '3'] + $this->validInput(),
                self::NOW,
            );
            self::fail('An age with no unit was accepted, so three days and three years became one record.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('patient_age_unit', $exception->errors);
        }

        self::assertSame(0, $this->repository->count());
    }

    public function testAnAgeWithNobodyToAttributeItToIsRefused(): void
    {
        try {
            $this->command->execute(
                new Actor(7, Role::Staff),
                ['patient_age_value' => '3', 'patient_age_unit' => 'years'] + $this->validInput(),
                self::NOW,
            );
            self::fail('An age was accepted with no record of who supplied it.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('patient_age_source', $exception->errors);
        }
    }

    public function testAnAgeThatCannotBelongToALivingPatientIsRefused(): void
    {
        try {
            $this->command->execute(
                new Actor(7, Role::Staff),
                [
                    'patient_age_value' => '200',
                    'patient_age_unit' => 'years',
                    'patient_age_source' => 'staff',
                ] + $this->validInput(),
                self::NOW,
            );
            self::fail('An age of 200 years was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('patient_age_value', $exception->errors);
        }
    }

    public function testAUnitWithoutAnAgeIsRefusedRatherThanStoredAlone(): void
    {
        try {
            $this->command->execute(
                new Actor(7, Role::Staff),
                ['patient_age_unit' => 'years', 'patient_age_source' => 'staff'] + $this->validInput(),
                self::NOW,
            );
            self::fail('A unit with no number was stored.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('patient_age_value', $exception->errors);
        }
    }

    public function testAReferenceNumberNeedsItsTypeBecauseTheTypeIsItsScope(): void
    {
        try {
            $this->command->execute(
                new Actor(7, Role::Staff),
                ['patient_identifier' => 'MRN-88421'] + $this->validInput(),
                self::NOW,
            );
            self::fail('A reference number with no type was accepted as if it were globally unique.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('patient_identifier_type', $exception->errors);
        }
    }

    public function testAReferenceNumberIsStoredWithItsTypeAndIssuingFacility(): void
    {
        $referral = $this->command->execute(
            new Actor(7, Role::Staff),
            [
                'patient_identifier' => 'MRN-88421',
                'patient_identifier_type' => 'hospital_number',
                'patient_identifier_facility' => 'Yekatit 12 Hospital',
            ] + $this->validInput(),
            self::NOW,
        );

        self::assertSame('MRN-88421', $referral->patientIdentifier);
        self::assertSame('Hospital number', $referral->identifierType()?->label());
        self::assertSame('Yekatit 12 Hospital', $referral->patientIdentifierFacility);
    }

    public function testAMalformedEmailIsRefusedRatherThanStoredAsText(): void
    {
        try {
            $this->command->execute(
                new Actor(7, Role::Staff),
                ['patient_email' => 'selamawit.girma.example.com'] + $this->validInput(),
                self::NOW,
            );
            self::fail('A malformed email address was stored.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('patient_email', $exception->errors);
        }
    }

    /**
     * The hazard `copyWith` creates.
     *
     * A status transition rebuilds the aggregate field by field. Any new field not
     * threaded through that method is silently dropped — and because every one of
     * these is nullable, nothing fails. This asserts the identity fields survive a
     * transition, which is the only thing that makes them worth storing.
     */
    public function testAStatusChangeKeepsThePatientIdentityIntact(): void
    {
        $referral = $this->command->execute(
            new Actor(7, Role::Staff),
            [
                'patient_age_value' => '42',
                'patient_age_unit' => 'years',
                'patient_age_source' => 'patient',
                'patient_identifier' => 'MRN-1',
                'patient_identifier_type' => 'hospital_number',
                'patient_identifier_facility' => 'Yekatit 12 Hospital',
                'patient_email' => 'selam@example.com',
                'preferred_language' => 'Amharic',
            ] + $this->validInput(),
            self::NOW,
        );

        $moved = $referral->withStatus(ReferralStatus::ClinicalReview, 2);

        self::assertSame(42, $moved->patientAgeValue);
        self::assertSame('years', $moved->patientAgeUnit);
        self::assertSame('patient', $moved->patientAgeSource);
        self::assertSame('MRN-1', $moved->patientIdentifier);
        self::assertSame('hospital_number', $moved->patientIdentifierType);
        self::assertSame('Yekatit 12 Hospital', $moved->patientIdentifierFacility);
        self::assertSame('selam@example.com', $moved->patientEmail);
        self::assertSame('Amharic', $moved->preferredLanguage);
    }
    /** @return array<string, string> */
    private function validInput(): array
    {
        return [
            'patient_name' => 'Selamawit Girma',
            'patient_phone' => '+251911000333',
            'patient_dob' => '1991-04-12',
            'procedure_code' => 'image_guided_biopsy',
            'requested_urgency' => 'routine',
            'clinical_summary' => 'Six weeks of right upper quadrant pain; ultrasound shows a 2 cm hepatic lesion.',
            'target_site' => 'Liver, segment VI',
        ];
    }
}
