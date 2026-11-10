<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Move an appointment, acquiring the new time before giving up the old one.
 *
 * WFS 8.4 asks for a specific failure mode: if the new slot is not available, the
 * patient keeps the appointment they already had. The obvious implementation -
 * cancel, then try to book - produces the alternative: a patient with no
 * appointment at all, discovered by the clinic only when they arrive.
 *
 * So the order here is not cosmetic. The repository inserts the new occupancy
 * first; only once every quantum is safely held does it release what the new
 * window does not include. A failure at any point rolls the whole unit of work
 * back, and the original booking is exactly as it was.
 *
 * The appointment must still be holding resources. A completed or cancelled
 * appointment is a record, not a booking, and moving it would rewrite history
 * instead of changing a plan.
 */
final class RescheduleAppointment
{
    /** States in which a patient is still expected and a move is a scheduling act. */
    private const MOVABLE = [
        AppointmentStatus::Held,
        AppointmentStatus::Scheduled,
        AppointmentStatus::Confirmed,
        AppointmentStatus::CheckedIn,
    ];

    public function __construct(
        private readonly AppointmentRepository $appointments,
        private readonly Transaction $transaction,
        private readonly OutboxRepository $outbox,
    ) {
    }

    /**
     * @param array<string, mixed> $input resource_id, starts_at (UTC), duration_minutes
     *
     * @throws AuthorisationException         the actor may not schedule
     * @throws AppointmentNotFoundException   the appointment does not exist
     * @throws ValidationException            the request is malformed, or nothing changes
     * @throws AppointmentTransitionException the appointment is not in a movable state
     * @throws SlotUnavailableException       the new time is taken; nothing was moved
     */
    public function execute(
        Actor $actor,
        string $reference,
        array $input,
        ?string $reason = null,
        ?string $nowUtc = null,
    ): Appointment {
        if (!$actor->can(Permission::ScheduleManage)) {
            throw new AuthorisationException($actor->role, Permission::ScheduleManage);
        }

        $appointment = $this->appointments->findByReference(trim($reference));

        if ($appointment === null) {
            throw new AppointmentNotFoundException($reference);
        }

        if (!in_array($appointment->status, self::MOVABLE, true)) {
            throw new AppointmentTransitionException(
                $appointment->status,
                $appointment->status,
                'Only a held, scheduled, confirmed or checked-in appointment can be moved.',
            );
        }

        $schedule = SchedulingInput::fromArray($input);
        $previousResourceId = $this->appointments->primaryResourceFor($appointment->id);

        if (
            $previousResourceId === $schedule->resourceId
            && $appointment->startsAtUtc === $schedule->startsAtUtc()
            && $appointment->durationMinutes === $schedule->durationMinutes
        ) {
            throw new ValidationException(['starts_at' => 'The appointment is already at that time.']);
        }

        $reason = $reason === null ? null : trim($reason);

        $movedAtUtc = $nowUtc ?? gmdate('Y-m-d H:i:s');

        return $this->transaction->run(function () use (
            $appointment,
            $schedule,
            $actor,
            $reason,
            $movedAtUtc,
        ): Appointment {
            $moved = $this->appointments->reschedule(
                $appointment,
                new RescheduleTarget(
                    resourceId: $schedule->resourceId,
                    startsAtUtc: $schedule->startsAtUtc(),
                    endsAtUtc: $schedule->endsAtUtc(),
                    durationMinutes: $schedule->durationMinutes,
                    slotStarts: $schedule->slotStarts(),
                ),
                $actor,
                $reason === '' ? null : $reason,
                $movedAtUtc,
            );

            // The new time is part of the dedupe key: a second move to a
            // different slot is a second message, which the old
            // (subject, template, channel) key would have swallowed.
            $this->outbox->record(null, $moved->referralId, [
                new OutboundMessage(
                    channel: NotificationChannel::Sms,
                    template: NotificationTemplate::AppointmentMoved,
                    recipient: $moved->patientPhone,
                    payload: [
                        'reference' => $moved->reference,
                        'scheduled_at_utc' => $moved->startsAtUtc,
                    ],
                    dedupeKey: 'appointment:' . $moved->reference . ':moved:' . $moved->startsAtUtc,
                ),
            ]);

            return $moved;
        });
    }
}
