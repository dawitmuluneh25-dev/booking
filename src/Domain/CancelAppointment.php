<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Cancel an appointment and hand the room back.
 *
 * The release is not done here, and deliberately not by a separate call: the
 * repository deletes the occupancy rows in the same transaction that sets
 * `cancelled`, because a two-step cancel would leave a room blocked for nobody
 * whenever the second step failed (Phase 5 criterion 3).
 *
 * A reason is required. Cancelling without one is indistinguishable from a
 * mistake, and the person who rebooks the patient needs to know whether this was
 * the patient's choice, a clinical deferral, or a scheduling error.
 */
final class CancelAppointment
{
    public function __construct(private readonly AppointmentRepository $appointments)
    {
    }

    /**
     * @throws AuthorisationException         the actor may not manage bookings
     * @throws AppointmentNotFoundException   the appointment does not exist
     * @throws ValidationException            no reason was given
     * @throws AppointmentTransitionException the appointment cannot be cancelled from here
     */
    public function execute(
        Actor $actor,
        string $reference,
        string $reason,
        ?string $nowUtc = null,
    ): Appointment {
        if (!$actor->can(Permission::BookingsManage)) {
            throw new AuthorisationException($actor->role, Permission::BookingsManage);
        }

        $appointment = $this->appointments->findByReference(trim($reference));

        if ($appointment === null) {
            throw new AppointmentNotFoundException($reference);
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new ValidationException(['reason' => 'Record why the appointment is being cancelled.']);
        }

        if (!$appointment->status->canTransitionTo(AppointmentStatus::Cancelled)) {
            throw new AppointmentTransitionException($appointment->status, AppointmentStatus::Cancelled);
        }

        return $this->appointments->transition(
            $appointment,
            AppointmentStatus::Cancelled,
            $actor,
            mb_substr($reason, 0, 255),
            $nowUtc ?? gmdate('Y-m-d H:i:s'),
        );
    }
}
