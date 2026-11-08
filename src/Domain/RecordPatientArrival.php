<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Record that the patient has arrived.
 *
 * Arrival moves the appointment to `checked_in` and does nothing else: the room
 * workflow after that — verification, ready, in progress — is separate, and
 * arriving is not the same as being ready to start (WFS 5.4).
 *
 * The patient is not looked up through the referral. An appointment can outlive a
 * correction to the referral, and the person in front of reception is the person
 * on the appointment.
 */
final class RecordPatientArrival
{
    public function __construct(private readonly AppointmentRepository $appointments)
    {
    }

    /**
     * @throws AuthorisationException          the actor may not work the desk
     * @throws AppointmentNotFoundException    the appointment does not exist
     * @throws AppointmentTransitionException  the appointment cannot be checked in
     */
    public function execute(
        Actor $actor,
        string $reference,
        ?string $nowUtc = null,
    ): Appointment {
        if (!$actor->can(Permission::BookingsManage)) {
            throw new AuthorisationException($actor->role, Permission::BookingsManage);
        }

        $appointment = $this->appointments->findByReference(trim($reference));

        if ($appointment === null) {
            throw new AppointmentNotFoundException($reference);
        }

        return $this->appointments->transition(
            $appointment,
            AppointmentStatus::CheckedIn,
            $actor,
            null,
            $nowUtc ?? gmdate('Y-m-d H:i:s'),
        );
    }
}
