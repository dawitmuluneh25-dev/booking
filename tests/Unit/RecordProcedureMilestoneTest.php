<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\Appointment;
use App\Domain\AppointmentNotFoundException;
use App\Domain\AppointmentStatus;
use App\Domain\AuthorisationException;
use App\Domain\ConsentOutcome;
use App\Domain\ConsentParticipant;
use App\Domain\ProcedureConsent;
use App\Domain\ProcedureMilestone;
use App\Domain\ProcedureMilestoneException;
use App\Domain\RecordProcedureMilestone;
use App\Domain\Role;
use App\Domain\ValidationException;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryAppointmentRepository;
use Tests\Support\InMemoryConsentRepository;
use Tests\Support\InMemoryProcedureMilestoneRepository;
use Tests\Support\InlineTransaction;
use Tests\Support\RecordingAuditTrail;

/**
 * The procedure day (§15) and AC-4's one concession: out of order is possible,
 * silent is not.
 */
final class RecordProcedureMilestoneTest extends TestCase
{
    private const NOW = '2026-11-20 09:12:00';

    private InMemoryAppointmentRepository $appointments;

    private InMemoryProcedureMilestoneRepository $milestones;

    private InMemoryConsentRepository $consents;

    private RecordingAuditTrail $audit;

    private RecordProcedureMilestone $command;

    protected function setUp(): void
    {
        $this->appointments = new InMemoryAppointmentRepository();
        $this->milestones = new InMemoryProcedureMilestoneRepository();
        $this->consents = new InMemoryConsentRepository();
        $this->audit = new RecordingAuditTrail();

        $this->command = new RecordProcedureMilestone(
            $this->appointments,
            $this->milestones,
            $this->consents,
            new Validator(),
            new InlineTransaction(),
            $this->audit,
        );
    }

    public function testArrivalRecordsTheEventAndChecksThePatientIn(): void
    {
        $appointment = $this->booked();

        $updated = $this->command->execute(
            new Actor(4, Role::Staff),
            $appointment->reference,
            ProcedureMilestone::Arrival,
            [],
            self::NOW,
        );

        self::assertSame(AppointmentStatus::CheckedIn, $updated->status);

        $records = $this->milestones->forAppointment($appointment->id);
        self::assertCount(1, $records);
        self::assertSame(ProcedureMilestone::Arrival, $records[0]->kind);
        self::assertSame(AppointmentStatus::CheckedIn, $records[0]->resultingStatus);
        self::assertSame(4, $records[0]->recordedByUserId);
        self::assertSame(self::NOW, $records[0]->occurredAtUtc);
    }

    /**
     * Decision 5: coordination is not administration. The administrator holds the
     * desk permission, so arrival is theirs to record; "the procedure started" is
     * not a sentence technical authority writes.
     */
    public function testAnAdministratorCannotRecordAClinicalMilestone(): void
    {
        $appointment = $this->booked();

        $this->expectException(AuthorisationException::class);

        $this->command->execute(
            new Actor(9, Role::Admin),
            $appointment->reference,
            ProcedureMilestone::ProcedureStarted,
            [],
            self::NOW,
        );
    }

    public function testAnAdministratorMayRecordArrivalBecauseItIsADeskFact(): void
    {
        $appointment = $this->booked();

        $updated = $this->command->execute(
            new Actor(9, Role::Admin),
            $appointment->reference,
            ProcedureMilestone::Arrival,
            [],
            self::NOW,
        );

        self::assertSame(AppointmentStatus::CheckedIn, $updated->status);
    }

    /**
     * Arrival is a desk fact, so it follows the desk permission — which the
     * radiologist does not hold. The refusal is about the act, not seniority.
     */
    public function testARadiologistCannotRecordArrival(): void
    {
        $appointment = $this->booked();

        $this->expectException(AuthorisationException::class);

        $this->command->execute(
            new Actor(2, Role::Radiologist),
            $appointment->reference,
            ProcedureMilestone::Arrival,
            [],
            self::NOW,
        );
    }

    public function testReceptionCannotRecordThatTheProcedureStarted(): void
    {
        $appointment = $this->checkedIn();

        $this->expectException(AuthorisationException::class);

        $this->command->execute(
            new Actor(4, Role::Staff),
            $appointment->reference,
            ProcedureMilestone::ProcedureStarted,
            [],
            self::NOW,
        );
    }

    /**
     * AC-4: no milestone out of order *without a reason*. The first attempt is
     * refused and names both the missing event and what a reason looks like.
     */
    public function testAStartedProcedureWithNoConsentCheckIsRefusedWithoutAReason(): void
    {
        $appointment = $this->checkedIn();

        try {
            $this->command->execute(
                new Actor(2, Role::Radiologist),
                $appointment->reference,
                ProcedureMilestone::ProcedureStarted,
                ['note' => ''],
                self::NOW,
            );
            self::fail('A procedure was recorded as started with no consent verification and no reason.');
        } catch (ValidationException $exception) {
            $message = (string) ($exception->errors['out_of_order_reason'] ?? '');

            self::assertStringContainsString('Consent verified', $message);
            self::assertStringContainsString('out of sequence', $message);
        }

        // Nothing was written and nothing moved: a refused record leaves no trace
        // that could later be read as an event that happened. The arrival recorded
        // by the fixture stands, and nothing was added after it.
        self::assertSame([ProcedureMilestone::Arrival], $this->milestones->kindsFor($appointment->id));
        self::assertSame(AppointmentStatus::CheckedIn, $this->statusOf($appointment));
    }

    public function testAStartedProcedureWithNoConsentCheckIsRecordedWithAReason(): void
    {
        $appointment = $this->checkedIn();

        $updated = $this->command->execute(
            new Actor(2, Role::Radiologist),
            $appointment->reference,
            ProcedureMilestone::ProcedureStarted,
            [
                'out_of_order_reason' => 'Patient brought through from the ward; consent document '
                    . 'witnessed at the bedside and recorded immediately after the start.',
            ],
            self::NOW,
        );

        self::assertSame(AppointmentStatus::InProgress, $updated->status);

        $records = $this->milestones->forAppointment($appointment->id);
        $record = $records[count($records) - 1];
        self::assertTrue($record->wasRecordedOutOfOrder());
        self::assertStringContainsString('brought through', (string) $record->outOfOrderReason);
        self::assertSame(AppointmentStatus::InProgress, $record->resultingStatus);
    }

    /**
     * The chain is walked one permitted edge at a time, so an operator who records
     * the start on a checked-in case does not leave the appointment reading
     * `checked_in` while a procedure is under way.
     */
    public function testStartingAProcedureWalksTheRoomWorkflowToInProgress(): void
    {
        $appointment = $this->checkedIn();
        $this->verifyConsent($appointment);

        $updated = $this->command->execute(
            new Actor(2, Role::Radiologist),
            $appointment->reference,
            ProcedureMilestone::ProcedureStarted,
            [],
            self::NOW,
        );

        self::assertSame(AppointmentStatus::InProgress, $updated->status);

        $history = $this->appointments->historyFor($appointment->id);
        self::assertSame(
            ['checked_in', 'preprocedure_verification', 'ready', 'in_progress'],
            array_map(static fn (array $row): string => $row['to'], $history),
        );
    }

    public function testTheSameMilestoneCannotBeRecordedTwiceInARow(): void
    {
        $appointment = $this->booked();

        $this->command->execute(
            new Actor(4, Role::Staff),
            $appointment->reference,
            ProcedureMilestone::Arrival,
            [],
            self::NOW,
        );

        try {
            $this->command->execute(
                new Actor(4, Role::Staff),
                $appointment->reference,
                ProcedureMilestone::Arrival,
                [],
                '2026-11-20 09:13:00',
            );
            self::fail('An arrival was recorded twice.');
        } catch (ProcedureMilestoneException $exception) {
            self::assertStringContainsString('already recorded', $exception->getMessage());
        }

        self::assertCount(1, $this->milestones->forAppointment($appointment->id));
    }

    public function testConsentVerifiedNamesTheConsentItVerified(): void
    {
        $appointment = $this->checkedIn();
        $consent = $this->consents->record($this->consent($appointment, ConsentOutcome::Agreed));

        $this->command->execute(
            new Actor(2, Role::Radiologist),
            $appointment->reference,
            ProcedureMilestone::ConsentVerified,
            [],
            self::NOW,
        );

        $records = $this->milestones->forAppointment($appointment->id);
        $record = $records[count($records) - 1];
        self::assertSame(ProcedureMilestone::ConsentVerified, $record->kind);
        self::assertSame($consent->id, $record->consentId);
        // A consent check changes no room state; the patient is still checked in.
        self::assertNull($record->resultingStatus);
    }

    public function testConsentCannotBeVerifiedWithNoConsentRecord(): void
    {
        $appointment = $this->checkedIn();

        try {
            $this->command->execute(
                new Actor(2, Role::Radiologist),
                $appointment->reference,
                ProcedureMilestone::ConsentVerified,
                [],
                self::NOW,
            );
            self::fail('Consent was verified against no record.');
        } catch (ProcedureMilestoneException $exception) {
            self::assertStringContainsString('No consent discussion is recorded', $exception->getMessage());
        }

        self::assertSame([ProcedureMilestone::Arrival], $this->milestones->kindsFor($appointment->id));
    }

    public function testConsentCannotBeVerifiedWhenTheRecordedConsentWasDeclined(): void
    {
        $appointment = $this->checkedIn();
        $this->consents->record($this->consent($appointment, ConsentOutcome::Declined));

        try {
            $this->command->execute(
                new Actor(2, Role::Radiologist),
                $appointment->reference,
                ProcedureMilestone::ConsentVerified,
                [],
                self::NOW,
            );
            self::fail('A declined consent was verified as an agreement.');
        } catch (ProcedureMilestoneException $exception) {
            self::assertStringContainsString('Declined', $exception->getMessage());
        }
    }

    public function testConsentCannotBeVerifiedOnAnAppointmentWithNoReferral(): void
    {
        $appointment = $this->appointments->book(
            $this->appointment(referralId: 0),
            1,
            ['2026-11-20 09:00:00', '2026-11-20 09:15:00'],
            new Actor(4, Role::Staff),
        );
        $this->appointments->transition(
            $appointment,
            AppointmentStatus::CheckedIn,
            new Actor(4, Role::Staff),
            null,
            self::NOW,
        );

        $this->expectException(ProcedureMilestoneException::class);
        $this->expectExceptionMessageMatches('/not linked to a referral/i');

        $this->command->execute(
            new Actor(2, Role::Radiologist),
            $appointment->reference,
            ProcedureMilestone::ConsentVerified,
            [],
            self::NOW,
        );
    }

    public function testAnArrivalCannotBeRecordedOnACaseAlreadyInProgress(): void
    {
        $appointment = $this->started();

        try {
            $this->command->execute(
                new Actor(4, Role::Staff),
                $appointment->reference,
                ProcedureMilestone::Arrival,
                [],
                '2026-11-20 09:30:00',
            );
            self::fail('An arrival was recorded for a procedure already under way.');
        } catch (ProcedureMilestoneException $exception) {
            self::assertStringContainsString(
                'cannot be recorded while the appointment is In progress',
                $exception->getMessage(),
            );
            self::assertStringContainsString('Scheduled or Confirmed', $exception->getMessage());
        }
    }

    public function testRecoveryIsRecordedAfterTheProcedureAndMovesNothing(): void
    {
        $appointment = $this->started();

        $this->command->execute(
            new Actor(2, Role::Radiologist),
            $appointment->reference,
            ProcedureMilestone::ProcedureCompleted,
            ['note' => 'Completed as planned; no complication.'],
            '2026-11-20 09:45:00',
        );

        $this->command->execute(
            new Actor(2, Role::Radiologist),
            $appointment->reference,
            ProcedureMilestone::RecoveryCompleted,
            [],
            '2026-11-20 10:30:00',
        );

        self::assertSame(
            [
                ProcedureMilestone::Arrival,
                ProcedureMilestone::ConsentVerified,
                ProcedureMilestone::ProcedureStarted,
                ProcedureMilestone::ProcedureCompleted,
                ProcedureMilestone::RecoveryCompleted,
            ],
            $this->milestones->kindsFor($appointment->id),
        );

        self::assertSame(AppointmentStatus::Completed, $this->statusOf($appointment));
    }

    public function testAnUnknownReferenceIsNotFound(): void
    {
        $this->expectException(AppointmentNotFoundException::class);

        $this->command->execute(
            new Actor(4, Role::Staff),
            'BK-000000-NOPE00',
            ProcedureMilestone::Arrival,
            [],
            self::NOW,
        );
    }

    public function testEveryRecordedMilestoneLeavesAnAuditEntryWithoutTheClinicalNote(): void
    {
        $appointment = $this->booked();

        $this->command->execute(
            new Actor(4, Role::Staff),
            $appointment->reference,
            ProcedureMilestone::Arrival,
            ['note' => 'Arrived with her daughter; wheelchair.'],
            self::NOW,
        );

        $entries = $this->audit->entries();
        self::assertCount(1, $entries);
        self::assertSame('appointment', $entries[0]->objectType);
        self::assertSame($appointment->reference, $entries[0]->objectRef);
        self::assertStringNotContainsString('wheelchair', json_encode($entries[0]->after) ?: '');
    }

    private function booked(): Appointment
    {
        return $this->appointments->book(
            $this->appointment(),
            1,
            ['2026-11-20 09:00:00', '2026-11-20 09:15:00'],
            new Actor(4, Role::Staff),
        );
    }

    private function checkedIn(): Appointment
    {
        $appointment = $this->booked();

        $this->command->execute(
            new Actor(4, Role::Staff),
            $appointment->reference,
            ProcedureMilestone::Arrival,
            [],
            self::NOW,
        );

        $stored = $this->appointments->findById($appointment->id);
        self::assertNotNull($stored);

        return $stored;
    }

    private function started(): Appointment
    {
        $appointment = $this->checkedIn();
        $this->verifyConsent($appointment);

        $started = $this->command->execute(
            new Actor(2, Role::Radiologist),
            $appointment->reference,
            ProcedureMilestone::ProcedureStarted,
            [],
            '2026-11-20 09:20:00',
        );

        return $started;
    }

    private function verifyConsent(Appointment $appointment): ProcedureConsent
    {
        $consent = $this->consents->record($this->consent($appointment, ConsentOutcome::Agreed));

        $this->command->execute(
            new Actor(2, Role::Radiologist),
            $appointment->reference,
            ProcedureMilestone::ConsentVerified,
            [],
            '2026-11-20 09:18:00',
        );

        return $consent;
    }

    private function consent(Appointment $appointment, ConsentOutcome $outcome): ProcedureConsent
    {
        return new ProcedureConsent(
            id: 0,
            referralId: $appointment->referralId,
            participant: ConsentParticipant::Self,
            participantName: $appointment->patientName,
            authorityVerified: false,
            authorityVerifiedByUserId: null,
            authorityVerifiedAtUtc: null,
            capacityAssessed: true,
            capacityNote: 'Oriented and able to repeat the plan back.',
            targetSite: 'Left breast, upper outer quadrant',
            informationVersion: 'HR-IR-CLIN-R1-001 §17.1',
            informationLanguage: 'en',
            discussionRecord: 'Benefits, risks and alternatives discussed; questions answered.',
            questionsRecorded: 'Asked whether the result would be available before travel.',
            interpreterInvolved: false,
            interpreterLanguage: null,
            outcome: $outcome,
            attestationReference: 'Consent form CF-2026-0417.',
            responsiblePractitionerUserId: 2,
            recordedByUserId: 2,
            consentedAtUtc: '2026-11-19 15:00:00',
        );
    }

    private function appointment(int $referralId = 1): Appointment
    {
        return new Appointment(
            id: 0,
            reference: 'BK-261120-ABC123',
            referralId: $referralId,
            status: AppointmentStatus::Scheduled,
            version: 1,
            startsAtUtc: '2026-11-20 09:00:00',
            endsAtUtc: '2026-11-20 09:30:00',
            durationMinutes: 30,
            patientName: 'Selamawit Girma',
            patientPhone: '+251911000333',
            createdByUserId: 4,
            createdAtUtc: '2026-11-08 15:00:00',
        );
    }

    private function statusOf(Appointment $appointment): AppointmentStatus
    {
        $stored = $this->appointments->findById($appointment->id);
        self::assertNotNull($stored);

        return $stored->status;
    }
}
