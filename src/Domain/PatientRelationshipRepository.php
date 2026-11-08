<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Recorded relationships between accounts.
 *
 * Separate from `users` because authority over another person's care is not a
 * property of the account: it is a relationship with a date, a level and an end.
 */
interface PatientRelationshipRepository
{
    /**
     * Patients this user may act for.
     *
     * Only relationships with `act` authority and no end date count; a `view`
     * relationship lets the relative see correspondence, not change anything, so it
     * does not appear here.
     *
     * @return list<int> patient user ids
     */
    public function patientsActedForBy(int $userId): array;

    /** Whether the relationship exists, is current, and carries acting authority. */
    public function actsFor(int $userId, int $patientUserId): bool;
}
