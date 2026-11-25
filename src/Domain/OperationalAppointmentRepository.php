<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Who holds each of the five appointments, and who deputises.
 *
 * The responsibility names come from `OperationalAppointment`, which is complete and needs no table.
 * This repository holds only the part that changes: a person, a deputy, and who recorded the change.
 * Keeping the two apart means a new responsibility is a code change and a new holder is an operational
 * one, which is the split D-07 and D-05 drew between them.
 */
interface OperationalAppointmentRepository
{
    /**
     * Every appointment, keyed by `OperationalAppointment::value`, including rows nobody holds.
     *
     * @return array<string, array{holder: ?string, deputy: ?string, updatedBy: ?string, updatedAt: ?string}>
     */
    public function all(): array;

    /**
     * Record who holds an appointment and who covers it.
     *
     * A null holder is meaningful: it clears the appointment and is how an appointment becomes vacant
     * again. An empty string is not — callers pass null, so "vacant" and "somebody typed a space" cannot
     * be confused in the table.
     */
    public function save(string $roleKey, ?string $holder, ?string $deputy, Actor $actor, string $nowUtc): void;
}
