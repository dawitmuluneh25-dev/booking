<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Close a procedure session and release the room.
 *
 * Completing holds a precondition that is easy to skip: the session must have been
 * in progress. Marking a case completed from `ready` would record a procedure that
 * nobody started, and it would release the room without the work having happened.
 *
 * The referral is not touched. Its lifecycle ended when the appointment was
 * created (WF-15); closing the room is not a second clinical decision.
 */
final class CompleteProcedureSession
{
    public function __construct(private readonly AppointmentRepository $appointments)
    {
    }

    /**
     * @throws AuthorisationException         the actor may not close a session
     * @throws AppointmentNotFoundException   the appointment does not exist
     * @throws AppointmentTransitionException the session was not in progress
     */
    public function execute(
        Actor $actor,
        string $reference,
        ?string $note = null,
        ?string $nowUtc = null,
    ): Appointment {
        if (!$actor->can(Permission::ProcedureCoordinate)) {
            throw new AuthorisationException($actor->role, Permission::ProcedureCoordinate);
        }

        $appointment = $this->appointments->findByReference(trim($reference));

        if ($appointment === null) {
            throw new AppointmentNotFoundException($reference);
        }

        if ($appointment->status !== AppointmentStatus::InProgress) {
            throw new AppointmentTransitionException(
                $appointment->status,
                AppointmentStatus::Completed,
                'A session can only be completed once it is in progress.',
            );
        }

        $note = $note === null ? null : trim($note);

        return $this->appointments->transition(
            $appointment,
            AppointmentStatus::Completed,
            $actor,
            $note === '' ? null : $note,
            $nowUtc ?? gmdate('Y-m-d H:i:s'),
        );
    }
}
