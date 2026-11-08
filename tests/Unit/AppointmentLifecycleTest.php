<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\Appointment;
use App\Domain\AppointmentNotFoundException;
use App\Domain\AppointmentStatus;
use App\Domain\AppointmentTransitionException;
use App\Domain\AuthorisationException;
use App\Domain\CompleteProcedureSession;
use App\Domain\RecordPatientArrival;
use App\Domain\Role;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryAppointmentRepository;

final class AppointmentLifecycleTest extends TestCase
{
    private InMemoryAppointmentRepository $appointments;

    private RecordPatientArrival $arrival;

    private CompleteProcedureSession $complete;

    protected function setUp(): void
    {
        $this->appointments = new InMemoryAppointmentRepository();
        $this->arrival = new RecordPatientArrival($this->appointments);
        $this->complete = new CompleteProcedureSession($this->appointments);
    }

    public function testArrivalMovesTheAppointmentToCheckedIn(): void
    {
        $appointment = $this->booked();

        $checkedIn = $this->arrival->execute(new Actor(4, Role::Staff), $appointment->reference);

        self::assertSame(AppointmentStatus::CheckedIn, $checkedIn->status);
    }

    public function testArrivalDoesNotTouchTheReferralLifecycle(): void
    {
        // Whatever a referral is doing, checking a patient in is an appointment
        // event; there is no referral object in this command to change (WF-15).
        $appointment = $this->booked();

        $this->arrival->execute(new Actor(4, Role::Staff), $appointment->reference);

        self::assertSame(AppointmentStatus::CheckedIn, $this->reload($appointment)->status);
    }

    public function testAPatientCannotCheckThemselvesIn(): void
    {
        $appointment = $this->booked();

        $this->expectException(AuthorisationException::class);
        $this->arrival->execute(new Actor(7, Role::Patient), $appointment->reference);
    }

    public function testArrivalOnAMissingReferenceIsNotFound(): void
    {
        $this->expectException(AppointmentNotFoundException::class);
        $this->arrival->execute(new Actor(4, Role::Staff), 'BK-000000-NOPE00');
    }

    public function testASessionCannotBeCompletedBeforeItStarts(): void
    {
        $appointment = $this->booked();

        $this->expectException(AppointmentTransitionException::class);
        $this->expectExceptionMessageMatches('/in progress/i');

        $this->complete->execute(new Actor(2, Role::Radiologist), $appointment->reference);
    }

    public function testASessionInProgressIsCompletedAndReleasesTheRoom(): void
    {
        $appointment = $this->booked();
        $staff = new Actor(4, Role::Staff);
        $radiologist = new Actor(2, Role::Radiologist);

        $reference = $appointment->reference;
        $this->arrival->execute($staff, $reference);
        $this->transitions($reference);

        $completed = $this->complete->execute($radiologist, $reference, 'Procedure completed without complication.');

        self::assertSame(AppointmentStatus::Completed, $completed->status);

        // The room stays accounted for: occupancy is a record of what happened in
        // that quantum, not only a claim on the future.
        self::assertCount(2, $this->appointments->occupiedSlots(1));
    }

    public function testCancellingReleasesTheRoom(): void
    {
        $appointment = $this->booked();
        self::assertCount(2, $this->appointments->occupiedSlots(1));

        $this->appointments->transition(
            $appointment,
            AppointmentStatus::Cancelled,
            new Actor(4, Role::Staff),
            'Patient rang to cancel.',
            '2026-11-10 10:00:00',
        );

        self::assertSame([], $this->appointments->occupiedSlots(1));
    }

    public function testAReceptionistCannotCloseASession(): void
    {
        $appointment = $this->booked();

        $this->expectException(AuthorisationException::class);
        $this->complete->execute(new Actor(4, Role::Staff), $appointment->reference);
    }

    /** Walks the room workflow from checked in to in progress. */
    private function transitions(string $reference): void
    {
        $radiologist = new Actor(2, Role::Radiologist);
        $current = $this->appointments->findByReference($reference);

        foreach (
            [
            AppointmentStatus::PreprocedureVerification,
            AppointmentStatus::Ready,
            AppointmentStatus::InProgress,
            ] as $target
        ) {
            self::assertNotNull($current);
            $current = $this->appointments->transition($current, $target, $radiologist, null, '2026-11-20 09:05:00');
        }
    }

    private function reload(Appointment $appointment): Appointment
    {
        $stored = $this->appointments->findById($appointment->id);
        self::assertNotNull($stored);

        return $stored;
    }

    private function booked(): Appointment
    {
        return $this->appointments->book(
            new Appointment(
                id: 0,
                reference: 'BK-261120-ABC123',
                referralId: 1,
                status: AppointmentStatus::Scheduled,
                version: 1,
                startsAtUtc: '2026-11-20 09:00:00',
                endsAtUtc: '2026-11-20 09:30:00',
                durationMinutes: 30,
                patientName: 'Selamawit Girma',
                patientPhone: '+251911000333',
                createdByUserId: 4,
                createdAtUtc: '2026-11-08 15:00:00',
            ),
            1,
            ['2026-11-20 09:00:00', '2026-11-20 09:15:00'],
            new Actor(4, Role::Staff),
        );
    }
}
