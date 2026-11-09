<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * An appointment with the room it occupies, for the day board.
 *
 * The appointment itself does not carry a resource id, because the same
 * appointment can have several resources attached (a suite and a supervising
 * clinician). The board only needs the primary one, so it is carried alongside
 * rather than added to the aggregate.
 */
final readonly class ScheduledAppointment
{
    public function __construct(
        public Appointment $appointment,
        public int $resourceId,
    ) {
    }
}
