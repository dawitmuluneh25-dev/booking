<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\AcknowledgeUrgentCase;
use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\RegisterUrgentCase;
use App\Domain\Role;
use App\Domain\UrgentCase;
use App\Domain\UrgentCaseNotFoundException;
use App\Domain\UrgentCoordination;
use App\Domain\UrgentStatus;
use App\Domain\UrgentTransitionException;
use App\Domain\ValidationException;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryUrgentCaseRepository;

final class UrgentCaseCommandTest extends TestCase
{
    private const NOW = '2026-11-08 13:00:00';

    private InMemoryUrgentCaseRepository $repository;

    private RegisterUrgentCase $register;

    private AcknowledgeUrgentCase $acknowledge;

    protected function setUp(): void
    {
        $this->repository = new InMemoryUrgentCaseRepository();
        $this->register = new RegisterUrgentCase($this->repository, new Validator());
        $this->acknowledge = new AcknowledgeUrgentCase($this->repository);
    }

    public function testACaseStartsReceivedAndUnacknowledged(): void
    {
        $case = $this->registerCase();

        self::assertSame(UrgentStatus::Received, $case->urgency);
        self::assertSame(UrgentCoordination::Unacknowledged, $case->coordination);
        self::assertSame(RegisterUrgentCase::PROCEDURE_CODE, $case->procedureCode);
        self::assertStringStartsWith('UR-', $case->reference);
        self::assertCount(1, $this->repository->historyFor($case->id));
    }

    public function testRegistrationIsNeverGatedOnPayment(): void
    {
        // Registration needs no fee, invoice or payment status: the case is
        // actionable the moment it exists. The schema half of this claim — that the
        // urgent tables carry no payment columns at all — is asserted in
        // MigrationIntegrityTest::testTheUrgentWorkflowHasNoPaymentGate.
        $case = $this->registerCase();

        self::assertGreaterThan(0, $case->id);
        self::assertSame(UrgentStatus::Received, $case->urgency);
        self::assertSame(UrgentCoordination::Unacknowledged, $case->coordination);
    }

    public function testACaseWithoutAnAgeIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $this->register->execute(
            new Actor(3, Role::Physician),
            ['patient_dob' => '', 'patient_age_months' => ''] + $this->input(),
            self::NOW,
        );
    }

    public function testAgeInMonthsIsAcceptedWithoutADateOfBirth(): void
    {
        $case = $this->register->execute(
            new Actor(3, Role::Physician),
            ['patient_dob' => '', 'patient_age_months' => '14'] + $this->input(),
            self::NOW,
        );

        self::assertNull($case->patientDob);
        self::assertSame(14, $case->patientAgeMonths);
    }

    public function testARadiologistCannotRegisterACase(): void
    {
        $this->expectException(AuthorisationException::class);

        $this->register->execute(new Actor(2, Role::Radiologist), $this->input(), self::NOW);
    }

    public function testTheReferringPhysicianIsRecordedAsTheReferrer(): void
    {
        $case = $this->register->execute(new Actor(3, Role::Physician), $this->input(), self::NOW);

        self::assertSame(3, $case->referringUserId);
    }

    public function testAcknowledgeMovesCoordinationAndNamesThePerson(): void
    {
        $case = $this->registerCase();

        $acknowledged = $this->acknowledge->execute(
            new Actor(4, Role::Staff),
            $case->id,
            'telephone',
            'Called the ward; child is being kept nil by mouth.',
            self::NOW,
        );

        self::assertSame(UrgentCoordination::Acknowledged, $acknowledged->coordination);

        // Acknowledging does not decide anything clinically.
        self::assertSame(UrgentStatus::Received, $acknowledged->urgency);

        $records = $this->repository->acknowledgementsFor($case->id);
        self::assertCount(1, $records);
        self::assertSame(4, $records[0]['user_id']);
        self::assertSame('telephone', $records[0]['channel']);
    }

    public function testACaseCannotBeAcknowledgedTwice(): void
    {
        $case = $this->registerCase();
        $this->acknowledge->execute(new Actor(4, Role::Staff), $case->id, 'application', null, self::NOW);

        $this->expectException(UrgentTransitionException::class);
        $this->acknowledge->execute(new Actor(5, Role::Coordinator), $case->id, 'application', null, self::NOW);
    }

    public function testAPatientCannotAcknowledgeOnBehalfOfStaff(): void
    {
        $case = $this->registerCase();

        $this->expectException(UrgentTransitionException::class);
        $this->acknowledge->execute(new Actor(7, Role::Patient), $case->id, 'application', null, self::NOW);
    }

    public function testAnUnknownAcknowledgedChannelIsRefused(): void
    {
        $case = $this->registerCase();

        $this->expectException(ValidationException::class);
        $this->acknowledge->execute(new Actor(4, Role::Staff), $case->id, 'telepathy', null, self::NOW);
    }

    public function testAcknowledgingAMissingCaseIsNotFound(): void
    {
        $this->expectException(UrgentCaseNotFoundException::class);
        $this->acknowledge->execute(new Actor(4, Role::Staff), 999, 'application', null, self::NOW);
    }

    private function registerCase(): UrgentCase
    {
        return $this->register->execute(new Actor(3, Role::Physician), $this->input(), self::NOW);
    }

    /** @return array<string, string> */
    private function input(): array
    {
        return [
            'patient_name' => 'Bereket Tesfaye',
            'patient_dob' => '2025-09-02',
            'patient_age_months' => '14',
            'guardian_name' => 'Tesfaye Alemu',
            'guardian_phone' => '+251911222444',
            'guardian_relationship' => 'Father',
            'referring_physician_name' => 'Dr Hana',
            'referring_facility' => 'Adama Hospital',
            'clinical_summary' => 'Twelve hours of colicky abdominal pain with redcurrant stool;
                ileocolic intussusception on ultrasound.',
            'imaging_summary' => 'Target sign on transverse view, 3 cm.',
        ];
    }
}
