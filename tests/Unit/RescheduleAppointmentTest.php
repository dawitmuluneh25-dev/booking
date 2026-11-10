<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\Appointment;
use App\Domain\AppointmentStatus;
use App\Domain\AppointmentTransitionException;
use App\Domain\CancelAppointment;
use App\Domain\NotificationTemplate;
use App\Domain\RescheduleAppointment;
use App\Domain\Role;
use App\Domain\SlotUnavailableException;
use App\Domain\ValidationException;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryAppointmentRepository;
use Tests\Support\InMemoryOutboxRepository;
use Tests\Support\InlineTransaction;

/**
 * Phase 5 criteria 2 and 3.
 *
 * The reschedule tests all ask the same question in different ways: after the
 * operation, is the patient still booked for the time the clinic believes? The
 * interesting case is the failure, because a naive implementation leaves them
 * with nothing.
 */
final class RescheduleAppointmentTest extends TestCase
{
    private const NOW = '2026-11-09 09:00:00';

    private InMemoryAppointmentRepository $appointments;

    private RescheduleAppointment $reschedule;

    private CancelAppointment $cancel;

    private Appointment $appointment;

    private InMemoryOutboxRepository $outbox;

    protected function setUp(): void
    {
        $this->appointments = new InMemoryAppointmentRepository();
        $this->outbox = new InMemoryOutboxRepository();
        $transaction = new InlineTransaction();

        $this->reschedule = new RescheduleAppointment($this->appointments, $transaction, $this->outbox);
        $this->cancel = new CancelAppointment($this->appointments, $transaction, $this->outbox);

        $this->appointment = $this->appointments->book(
            $this->entity('2031-06-03 09:00:00', '2031-06-03 10:00:00', 60),
            1,
            ['2031-06-03 09:00:00', '2031-06-03 09:15:00', '2031-06-03 09:30:00', '2031-06-03 09:45:00'],
            new Actor(7, Role::Staff),
        );
    }

    public function testMovingAnAppointmentReleasesTheOldQuantaAndTakesTheNew(): void
    {
        $moved = $this->reschedule->execute($this->staff(), $this->appointment->reference, [
            'resource_id' => '1',
            'starts_at' => '2031-06-03 14:00:00',
            'duration_minutes' => '60',
        ], 'Patient asked for the afternoon.', self::NOW);

        self::assertSame('2031-06-03 14:00:00', $moved->startsAtUtc);
        self::assertSame(2, $moved->version);
        self::assertSame(
            ['2031-06-03 14:00:00', '2031-06-03 14:15:00', '2031-06-03 14:30:00', '2031-06-03 14:45:00'],
            $this->appointments->occupiedSlots(1),
        );
        self::assertCount(1, $this->appointments->reschedules());
    }

    public function testAFailedMoveLeavesTheOriginalAppointmentExactlyWhereItWas(): void
    {
        // Somebody else already holds 15:00.
        $this->appointments->book(
            $this->entity('2031-06-03 15:00:00', '2031-06-03 16:00:00', 60, 'BK-OTHER'),
            1,
            ['2031-06-03 15:00:00', '2031-06-03 15:15:00', '2031-06-03 15:30:00', '2031-06-03 15:45:00'],
            new Actor(7, Role::Staff),
        );

        try {
            $this->reschedule->execute($this->staff(), $this->appointment->reference, [
                'resource_id' => '1',
                'starts_at' => '2031-06-03 15:00:00',
                'duration_minutes' => '60',
            ], null, self::NOW);
            self::fail('A reschedule into an occupied slot succeeded.');
        } catch (SlotUnavailableException) {
            $reloaded = $this->appointments->findByReference($this->appointment->reference);

            self::assertNotNull($reloaded);
            self::assertSame('2031-06-03 09:00:00', $reloaded->startsAtUtc);
            self::assertSame(1, $reloaded->version);
            self::assertContains('2031-06-03 09:00:00', $this->appointments->occupiedSlots(1));
            self::assertSame([], $this->appointments->reschedules());
        }
    }

    public function testAnOverlappingMoveKeepsTheQuantasItStillNeeds(): void
    {
        // 09:00 → 11:00 overlaps the current 09:00-10:00 booking by four quanta.
        $moved = $this->reschedule->execute($this->staff(), $this->appointment->reference, [
            'resource_id' => '1',
            'starts_at' => '2031-06-03 09:30:00',
            'duration_minutes' => '90',
        ], null, self::NOW);

        self::assertSame('2031-06-03 09:30:00', $moved->startsAtUtc);
        self::assertSame(
            [
                '2031-06-03 09:30:00',
                '2031-06-03 09:45:00',
                '2031-06-03 10:00:00',
                '2031-06-03 10:15:00',
                '2031-06-03 10:30:00',
                '2031-06-03 10:45:00',
            ],
            $this->appointments->occupiedSlots(1),
        );
    }

    public function testMovingBetweenResourcesTakesTheNewRoomFirst(): void
    {
        $moved = $this->reschedule->execute($this->staff(), $this->appointment->reference, [
            'resource_id' => '2',
            'starts_at' => '2031-06-03 09:00:00',
            'duration_minutes' => '60',
        ], 'Suite 1 is out of service.', self::NOW);

        self::assertSame('2031-06-03 09:00:00', $moved->startsAtUtc);
        self::assertSame([], $this->appointments->occupiedSlots(1));
        self::assertCount(4, $this->appointments->occupiedSlots(2));
        self::assertSame(2, $this->appointments->primaryResourceFor($moved->id));
    }

    public function testAMoveThatChangesNothingIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $this->reschedule->execute($this->staff(), $this->appointment->reference, [
            'resource_id' => '1',
            'starts_at' => '2031-06-03 09:00:00',
            'duration_minutes' => '60',
        ], null, self::NOW);
    }

    public function testACancelledAppointmentCannotBeMoved(): void
    {
        $completed = $this->appointments->transition(
            $this->appointment,
            AppointmentStatus::Cancelled,
            new Actor(7, Role::Staff),
            'Patient withdrew.',
            self::NOW,
        );

        $this->expectException(AppointmentTransitionException::class);

        $this->reschedule->execute($this->staff(), $completed->reference, [
            'resource_id' => '1',
            'starts_at' => '2031-06-04 09:00:00',
            'duration_minutes' => '60',
        ], null, self::NOW);
    }

    public function testCancellingReleasesEveryQuantumInTheSameOperation(): void
    {
        $cancelled = $this->cancel->execute(
            $this->staff(),
            $this->appointment->reference,
            'Patient called to cancel.',
            self::NOW,
        );

        self::assertSame(AppointmentStatus::Cancelled, $cancelled->status);
        self::assertSame([], $this->appointments->occupiedSlots(1));
    }

    public function testCancellingWithoutAReasonIsRefused(): void
    {
        try {
            $this->cancel->execute($this->staff(), $this->appointment->reference, '   ', self::NOW);
            self::fail('A cancellation with no reason was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('reason', $exception->errors);
            self::assertCount(4, $this->appointments->occupiedSlots(1));
        }
    }

    public function testAnotherRoleCannotCancel(): void
    {
        $patient = new Actor(3, Role::Patient);

        $this->expectException(\App\Domain\AuthorisationException::class);

        $this->cancel->execute($patient, $this->appointment->reference, 'Changed my mind.', self::NOW);
    }

    public function testEveryMoveQueuesItsOwnMessageInClinicTime(): void
    {
        $first = $this->reschedule->execute($this->staff(), $this->appointment->reference, [
            'resource_id' => '1',
            'starts_at' => '2031-06-03 14:00:00',
            'duration_minutes' => '60',
        ], 'Patient asked for the afternoon.', self::NOW);

        $second = $this->reschedule->execute(
            $this->staff(),
            $this->appointment->reference,
            ['resource_id' => '1', 'starts_at' => '2031-06-04 08:00:00', 'duration_minutes' => '60'],
            'Patient asked for the next morning.',
            self::NOW,
        );

        $queued = $this->outbox->recent(10);
        self::assertCount(2, $queued, 'A second move must not be swallowed as a duplicate.');

        $keys = array_map(static fn ($message): string => (string) $message->dedupeKey, $queued);
        // recent() is newest first.
        self::assertSame(
            [
                'appointment:' . $second->reference . ':moved:2031-06-04 08:00:00',
                'appointment:' . $first->reference . ':moved:2031-06-03 14:00:00',
            ],
            $keys,
        );

        $body = $queued[0]->body(new DateTimeZone('Africa/Addis_Ababa'));
        self::assertStringContainsString('11:00', $body);
        self::assertStringContainsString($second->reference, $body);
        self::assertStringNotContainsString($this->appointment->patientName, $body);
    }

    public function testCancellingQueuesOneMessageWithoutTheReason(): void
    {
        $this->cancel->execute(
            $this->staff(),
            $this->appointment->reference,
            'Patient has a conflicting hospital admission.',
            self::NOW,
        );

        $queued = $this->outbox->recent(10);
        self::assertCount(1, $queued);
        self::assertSame(NotificationTemplate::AppointmentCancelled, $queued[0]->template);

        $body = $queued[0]->body(new DateTimeZone('Africa/Addis_Ababa'));
        self::assertStringContainsString($this->appointment->reference, $body);
        self::assertStringNotContainsString('conflicting hospital admission', $body);
        self::assertStringNotContainsString($this->appointment->patientName, $body);
    }
    private function staff(): Actor
    {
        return new Actor(7, Role::Staff);
    }

    private function entity(
        string $startsAt,
        string $endsAt,
        int $duration,
        string $reference = 'BK-DEMO01',
    ): Appointment {
        return new Appointment(
            id: 0,
            reference: $reference,
            referralId: 1,
            status: AppointmentStatus::Scheduled,
            version: 1,
            startsAtUtc: $startsAt,
            endsAtUtc: $endsAt,
            durationMinutes: $duration,
            patientName: 'Patient One',
            patientPhone: '+251911000001',
            createdByUserId: 7,
            createdAtUtc: self::NOW,
        );
    }
}
