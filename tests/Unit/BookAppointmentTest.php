<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\Appointment;
use App\Domain\AuthorisationException;
use App\Domain\BookAppointment;
use App\Domain\ClinicalDecision;
use App\Domain\ClinicalDecisionType;
use App\Domain\Referral;
use App\Domain\ReferralStatus;
use App\Domain\ReferralTransitionException;
use App\Domain\Role;
use App\Domain\SlotUnavailableException;
use App\Domain\Transaction;
use App\Domain\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryAppointmentRepository;
use Tests\Support\InMemoryReferralRepository;

final class BookAppointmentTest extends TestCase
{
    private const NOW = '2026-11-08 15:00:00';

    private InMemoryReferralRepository $referrals;

    private InMemoryAppointmentRepository $appointments;

    private BookAppointment $command;

    protected function setUp(): void
    {
        $this->referrals = new InMemoryReferralRepository();
        $this->appointments = new InMemoryAppointmentRepository();
        $this->command = new BookAppointment(
            $this->referrals,
            $this->appointments,
            $this->passthroughTransaction(),
        );
    }

    public function testAnApprovedReferralIsBookedAndConvertedInOneGo(): void
    {
        $referral = $this->approvedReferral();

        $appointment = $this->command->execute(
            new Actor(4, Role::Staff),
            $referral->id,
            ['resource_id' => 1, 'starts_at' => '2026-11-20 09:00:00', 'duration_minutes' => 45],
            self::NOW,
        );

        self::assertGreaterThan(0, $appointment->id);
        self::assertSame('2026-11-20 09:45:00', $appointment->endsAtUtc);
        self::assertSame($referral->patientName, $appointment->patientName);

        // Three 15-minute quanta, all held by this appointment.
        self::assertSame(
            ['2026-11-20 09:00:00', '2026-11-20 09:15:00', '2026-11-20 09:30:00'],
            $this->appointments->occupiedSlots(1),
        );

        $reloaded = $this->referrals->findById($referral->id);
        self::assertNotNull($reloaded);
        self::assertSame(ReferralStatus::ConvertedToAppointment, $reloaded->status);
    }

    public function testBookingIsRefusedWhenThereIsNoClinicalDecision(): void
    {
        $referral = $this->referralAt(ReferralStatus::ApprovedForScheduling);

        $this->expectException(ReferralTransitionException::class);
        $this->expectExceptionMessageMatches('/standing clinical approval/i');

        $this->command->execute(
            new Actor(4, Role::Staff),
            $referral->id,
            ['resource_id' => 1, 'starts_at' => '2026-11-20 09:00:00', 'duration_minutes' => 45],
            self::NOW,
        );
    }

    public function testBookingIsRefusedWhenTheApprovalHasBeenSuperseded(): void
    {
        // Approved once, then deferred. The referral still reads
        // "approved_for_scheduling"; the standing decision says otherwise, and the
        // decision is the record of clinical judgement (WF-02).
        $referral = $this->approvedReferral();

        $standing = $this->referrals->currentDecision($referral->id);
        self::assertNotNull($standing);

        $this->referrals->recordDecision($this->decision($referral->id, ClinicalDecisionType::Deferred, $standing->id));

        $this->expectException(ReferralTransitionException::class);
        $this->command->execute(
            new Actor(4, Role::Staff),
            $referral->id,
            ['resource_id' => 1, 'starts_at' => '2026-11-20 09:00:00', 'duration_minutes' => 45],
            self::NOW,
        );

        self::assertSame(0, $this->appointments->count());
    }

    public function testASecondBookingOfTheSameQuantumIsRefusedAndLeavesNothingBehind(): void
    {
        $first = $this->approvedReferral();
        $second = $this->approvedReferral();

        $this->command->execute(
            new Actor(4, Role::Staff),
            $first->id,
            ['resource_id' => 1, 'starts_at' => '2026-11-20 09:00:00', 'duration_minutes' => 30],
            self::NOW,
        );

        try {
            $this->command->execute(
                new Actor(4, Role::Staff),
                $second->id,
                ['resource_id' => 1, 'starts_at' => '2026-11-20 09:15:00', 'duration_minutes' => 30],
                self::NOW,
            );
            self::fail('The same quantum was booked twice.');
        } catch (SlotUnavailableException $exception) {
            self::assertSame(1, $exception->resourceId);
            self::assertSame('2026-11-20 09:15:00', $exception->slotStartUtc);
        }

        self::assertSame(1, $this->appointments->count());
        self::assertSame(
            ['2026-11-20 09:00:00', '2026-11-20 09:15:00'],
            $this->appointments->occupiedSlots(1),
        );

        $notBooked = $this->referrals->findById($second->id);
        self::assertNotNull($notBooked);
        self::assertSame(ReferralStatus::ApprovedForScheduling, $notBooked->status);
    }

    public function testBookingIsRefusedUnlessTheReferralIsApprovedForScheduling(): void
    {
        $referral = $this->referralAt(ReferralStatus::ClinicallyApproved);
        $this->referrals->recordDecision($this->decision($referral->id, ClinicalDecisionType::Approved, null));

        $this->expectException(ReferralTransitionException::class);
        $this->command->execute(
            new Actor(4, Role::Staff),
            $referral->id,
            ['resource_id' => 1, 'starts_at' => '2026-11-20 09:00:00', 'duration_minutes' => 30],
            self::NOW,
        );
    }

    public function testAPartialQuantumIsRefused(): void
    {
        $referral = $this->approvedReferral();

        $this->expectException(ValidationException::class);
        $this->command->execute(
            new Actor(4, Role::Staff),
            $referral->id,
            ['resource_id' => 1, 'starts_at' => '2026-11-20 09:00:00', 'duration_minutes' => 20],
            self::NOW,
        );
    }

    public function testAnInvalidStartTimeIsRefused(): void
    {
        $referral = $this->approvedReferral();

        $this->expectException(ValidationException::class);
        $this->command->execute(
            new Actor(4, Role::Staff),
            $referral->id,
            ['resource_id' => 1, 'starts_at' => 'next Tuesday', 'duration_minutes' => 30],
            self::NOW,
        );
    }

    public function testAPatientCannotBook(): void
    {
        $referral = $this->approvedReferral();

        $this->expectException(AuthorisationException::class);
        $this->command->execute(
            new Actor(7, Role::Patient),
            $referral->id,
            ['resource_id' => 1, 'starts_at' => '2026-11-20 09:00:00', 'duration_minutes' => 30],
            self::NOW,
        );
    }

    private function approvedReferral(): Referral
    {
        $referral = $this->referralAt(ReferralStatus::ApprovedForScheduling);
        $this->referrals->recordDecision($this->decision($referral->id, ClinicalDecisionType::Approved, null));

        return $referral;
    }

    private function referralAt(ReferralStatus $status): Referral
    {
        $referral = new Referral(
            id: 0,
            reference: 'RF-BOOK-' . bin2hex(random_bytes(3)),
            status: $status,
            version: 1,
            patientName: 'Selamawit Girma',
            patientPhone: '+251911000333',
            patientDob: '1991-04-12',
            guardianName: null,
            guardianPhone: null,
            guardianRelationship: null,
            procedureCode: 'fluoroscopic_biopsy',
            requestedUrgency: 'routine',
            clinicalSummary: 'Six weeks of right upper quadrant pain.',
            targetSite: 'Liver, segment VI',
            referringPhysicianName: 'Dr Bekele',
            referringFacility: 'St Paul Referral',
            referringPhysicianPhone: null,
            submittedByUserId: 3,
            submittedOnBehalfOf: 'referrer',
            createdAtUtc: '2026-11-07 08:00:00',
        );

        return $this->referrals->create($referral, new Actor(3, Role::Physician), '2026-11-07 08:00:00');
    }

    private function decision(int $referralId, ClinicalDecisionType $type, ?int $supersedes): ClinicalDecision
    {
        return new ClinicalDecision(
            id: 0,
            referralId: $referralId,
            type: $type,
            rationale: 'Reviewed the imaging and the referral letter in full before deciding.',
            technique: null,
            modality: null,
            sedation: null,
            estimatedDurationMinutes: null,
            preprocedureConditions: null,
            redirectDestination: null,
            informationRequested: null,
            decidedByUserId: 2,
            decidedAtUtc: '2026-11-08 09:00:00',
            supersedesId: $supersedes,
        );
    }

    private function passthroughTransaction(): Transaction
    {
        return new class implements Transaction {
            public function run(callable $work): mixed
            {
                return $work();
            }

            public function depth(): int
            {
                return 0;
            }
        };
    }
}
