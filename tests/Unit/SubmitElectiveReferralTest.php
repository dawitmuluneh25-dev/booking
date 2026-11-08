<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\IdempotencyConflictException;
use App\Domain\ReferralStatus;
use App\Domain\Role;
use App\Domain\SubmitElectiveReferral;
use App\Domain\ValidationException;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryIdempotencyStore;
use Tests\Support\InMemoryReferralRepository;

final class SubmitElectiveReferralTest extends TestCase
{
    private const NOW = '2026-11-08 09:00:00';

    private InMemoryReferralRepository $repository;

    private InMemoryIdempotencyStore $idempotency;

    private SubmitElectiveReferral $command;

    protected function setUp(): void
    {
        $this->repository = new InMemoryReferralRepository();
        $this->idempotency = new InMemoryIdempotencyStore();
        $this->command = new SubmitElectiveReferral($this->repository, new Validator(), $this->idempotency);
    }

    public function testPatientSubmissionStartsAtSubmittedAndNeverAtApproved(): void
    {
        $referral = $this->command->execute(
            new Actor(7, Role::Patient),
            $this->validInput(),
            self::NOW,
        );

        self::assertGreaterThan(0, $referral->id);
        self::assertSame(ReferralStatus::Submitted, $referral->status);
        self::assertSame(1, $referral->version);
        self::assertSame('self', $referral->submittedOnBehalfOf);
        self::assertSame(7, $referral->submittedByUserId);
        self::assertSame(self::NOW, $referral->createdAtUtc);
    }

    public function testSubmissionWritesAnOpeningHistoryRow(): void
    {
        $referral = $this->command->execute(new Actor(7, Role::Patient), $this->validInput(), self::NOW);

        $history = $this->repository->historyFor($referral->id);

        self::assertCount(1, $history);
        self::assertNull($history[0]['from']);
        self::assertSame('submitted', $history[0]['to']);
        self::assertSame('patient', $history[0]['actor_role']);
    }

    public function testReferenceIsGeneratedRatherThanAcceptedFromInput(): void
    {
        $referral = $this->command->execute(
            new Actor(7, Role::Patient),
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
            new Actor(9, Role::Patient),
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

    public function testReferringPhysicianIsRecordedAsReferrer(): void
    {
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
                new Actor(7, Role::Patient),
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
                new Actor(7, Role::Patient),
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
                new Actor(7, Role::Patient),
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
            new Actor(7, Role::Patient),
            ['requested_urgency' => 'priority'] + $this->validInput(),
            self::NOW,
        );

        // The referrer's own request is stored, but submission produces no clinical
        // decision and no clinical priority (WF-11).
        self::assertSame('priority', $referral->requestedUrgency);
        self::assertSame(ReferralStatus::Submitted, $referral->status);
        self::assertCount(1, $this->repository->historyFor($referral->id));
    }

    public function testUnacceptedUrgencyIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $this->command->execute(
            new Actor(7, Role::Patient),
            ['requested_urgency' => 'emergency'] + $this->validInput(),
            self::NOW,
        );
    }

    public function testProcedureCodeShapeIsChecked(): void
    {
        $this->expectException(ValidationException::class);

        $this->command->execute(
            new Actor(7, Role::Patient),
            ['procedure_code' => 'Fluoroscopic Biopsy; DROP TABLE'] + $this->validInput(),
            self::NOW,
        );
    }

    public function testTheSameTokenReturnsTheFirstReferralInsteadOfASecond(): void
    {
        $actor = new Actor(7, Role::Patient);
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
        $actor = new Actor(7, Role::Patient);
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
        $actor = new Actor(7, Role::Patient);
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

    /** @return array<string, string> */
    private function validInput(): array
    {
        return [
            'patient_name' => 'Selamawit Girma',
            'patient_phone' => '+251911000333',
            'patient_dob' => '1991-04-12',
            'procedure_code' => 'fluoroscopic_biopsy',
            'requested_urgency' => 'routine',
            'clinical_summary' => 'Six weeks of right upper quadrant pain; ultrasound shows a 2 cm hepatic lesion.',
            'target_site' => 'Liver, segment VI',
        ];
    }
}
