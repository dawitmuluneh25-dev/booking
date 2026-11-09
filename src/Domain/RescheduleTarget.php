<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Where an appointment is being moved to.
 *
 * A value object rather than five positional arguments: `reschedule($appointment,
 * 3, '2031-06-03 09:00:00', ...)` is a call nobody can read, and getting the
 * order of two UTC strings wrong would move a patient to the wrong time without
 * any type complaining.
 */
final readonly class RescheduleTarget
{
    /** @param list<string> $slotStarts UTC quanta the appointment will occupy */
    public function __construct(
        public int $resourceId,
        public string $startsAtUtc,
        public string $endsAtUtc,
        public int $durationMinutes,
        public array $slotStarts,
    ) {
    }
}
