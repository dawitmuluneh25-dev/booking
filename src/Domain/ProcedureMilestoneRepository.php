<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Persistence boundary for the procedure-day event log (§15).
 *
 * Append-only, and the interface says so by having no method that could do
 * anything else: there is no `update()` to reach for, so a correction has to be a
 * new record and has to carry its own reason.
 */
interface ProcedureMilestoneRepository
{
    public function record(ProcedureMilestoneRecord $record): ProcedureMilestoneRecord;

    /**
     * Everything recorded for one appointment, oldest first.
     *
     * @return list<ProcedureMilestoneRecord>
     */
    public function forAppointment(int $appointmentId): array;

    /**
     * The same log for several appointments in one query, so a day's queue does
     * not turn into one round trip per case.
     *
     * @param list<int> $appointmentIds
     *
     * @return array<int, list<ProcedureMilestoneRecord>> keyed by appointment id
     */
    public function forAppointments(array $appointmentIds): array;
}
