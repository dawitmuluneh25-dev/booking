<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Persistence boundary for appointments and the occupancy they create.
 *
 * `book()` writes the appointment, its resource link and one `resource_slots` row
 * per quantum in a single transaction. The occupancy rows are not bookkeeping:
 * their primary key is the double-booking guard, so a second request for the same
 * quantum fails at the key rather than at a check that could race.
 */
interface AppointmentRepository
{
    /**
     * @param list<string> $slotStarts every quantum the appointment occupies, in UTC
     *
     * @throws SlotUnavailableException when any of those quanta is already taken
     */
    public function book(
        Appointment $appointment,
        int $resourceId,
        array $slotStarts,
        Actor $actor,
    ): Appointment;

    public function findByReference(string $reference): ?Appointment;

    public function findById(int $id): ?Appointment;

    /**
     * Move an appointment through its own lifecycle, guarded by the version and by
     * the appointment's transition table — never by the referral's (WF-15).
     *
     * @throws AppointmentTransitionException when the movement is not permitted
     */
    public function transition(
        Appointment $appointment,
        AppointmentStatus $target,
        Actor $actor,
        ?string $reason,
        string $occurredAtUtc,
    ): Appointment;
}
