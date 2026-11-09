<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The collaborators the scheduling screens need, resolved together.
 *
 * Same reason as `DocumentWorkspace`: a controller that took seven factories is
 * unreadable, and tests need one seam to substitute rather than seven.
 */
final readonly class ScheduleWorkspace
{
    public function __construct(
        public ScheduleBoard $board,
        public ReferralRepository $referrals,
        public AppointmentRepository $appointments,
        public BookAppointment $book,
        public RescheduleAppointment $reschedule,
        public CancelAppointment $cancel,
    ) {
    }
}
