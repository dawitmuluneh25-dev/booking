<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\ProcedureMilestone;
use App\Domain\ProcedureMilestoneRecord;
use App\Domain\ProcedureMilestoneRepository;

/**
 * The event log, in an array.
 *
 * It keeps insertion order rather than sorting by `occurred_at`, because that is
 * what the real repository's `ORDER BY occurred_at, id` comes to for records
 * written in sequence — and because a test that records a milestone with a
 * backdated timestamp is testing the *command*, not the ordering of the query.
 */
final class InMemoryProcedureMilestoneRepository implements ProcedureMilestoneRepository
{
    /** @var list<ProcedureMilestoneRecord> */
    private array $records = [];

    public function record(ProcedureMilestoneRecord $record): ProcedureMilestoneRecord
    {
        $stored = $record->withIdentity(count($this->records) + 1);
        $this->records[] = $stored;

        return $stored;
    }

    public function forAppointment(int $appointmentId): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (ProcedureMilestoneRecord $record): bool => $record->appointmentId === $appointmentId,
        ));
    }

    public function forAppointments(array $appointmentIds): array
    {
        $grouped = [];

        foreach ($this->records as $record) {
            if (in_array($record->appointmentId, $appointmentIds, true)) {
                $grouped[$record->appointmentId][] = $record;
            }
        }

        return $grouped;
    }

    /** @return list<ProcedureMilestoneRecord> */
    public function all(): array
    {
        return $this->records;
    }

    public function count(): int
    {
        return count($this->records);
    }

    /**
     * The kinds recorded against one appointment, in order.
     *
     * @return list<ProcedureMilestone>
     */
    public function kindsFor(int $appointmentId): array
    {
        return array_map(
            static fn (ProcedureMilestoneRecord $record): ProcedureMilestone => $record->kind,
            $this->forAppointment($appointmentId),
        );
    }
}
