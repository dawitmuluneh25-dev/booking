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

    /**
     * Take the resource's write lock for the rest of the current transaction.
     *
     * The lock is what turns "two schedulers both saw 09:00 free" into a queue
     * rather than a race, and it is always taken before any occupancy row is
     * written. When an operation touches two resources it locks them in ascending
     * id order, so two opposite moves cannot deadlock each other.
     *
     * Implementations must acquire it inside, not instead of, the caller's
     * transaction; a lock taken outside one is released immediately and buys
     * nothing.
     */
    public function lockResource(int $resourceId): void;

    /**
     * Move an appointment to a new time, acquiring the new occupancy before
     * releasing the old.
     *
     * If any new quantum is taken the whole operation fails and the original
     * booking is untouched - not "cancelled first, then rebooked" (WFS 8.4).
     *
     * @throws SlotUnavailableException        a new quantum is already held
     * @throws AppointmentTransitionException  another writer moved the appointment
     */
    public function reschedule(
        Appointment $appointment,
        RescheduleTarget $target,
        Actor $actor,
        ?string $reason,
        string $occurredAtUtc,
    ): Appointment;

    /** @return list<string> UTC starts this appointment currently holds */
    public function occupiedStartsFor(int $appointmentId): array;

    /** The resource recorded as primary for an appointment, or null. */
    public function primaryResourceFor(int $appointmentId): ?int;

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
