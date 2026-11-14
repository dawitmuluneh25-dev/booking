<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\ProcedureMilestoneRecord;
use App\Domain\ProcedureMilestoneRepository;
use PDO;

/**
 * MySQL implementation of the procedure-day event log.
 *
 * The only statement this class issues against `procedure_milestones` is an
 * INSERT and two SELECTs. That is not an oversight: an event that happened is not
 * edited, and the table has no `updated_at` column to edit either. The absence is
 * the guarantee — a future writer cannot quietly add an UPDATE without changing
 * this class's shape first.
 */
final class PdoProcedureMilestoneRepository implements ProcedureMilestoneRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(ProcedureMilestoneRecord $record): ProcedureMilestoneRecord
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO procedure_milestones
                (appointment_id, kind, occurred_at, recorded_by_user_id, recorded_by_role,
                 note, consent_id, resulting_status, out_of_order_reason, created_at)
             VALUES
                (:appointment_id, :kind, :occurred_at, :recorded_by, :recorded_by_role,
                 :note, :consent_id, :resulting_status, :out_of_order_reason, :created_at)'
        );
        $statement->execute([
            'appointment_id' => $record->appointmentId,
            'kind' => $record->kind->value,
            'occurred_at' => $record->occurredAtUtc,
            'recorded_by' => $record->recordedByUserId,
            'recorded_by_role' => $record->recordedByRole->value,
            'note' => $record->note,
            'consent_id' => $record->consentId,
            'resulting_status' => $record->resultingStatus?->value,
            'out_of_order_reason' => $record->outOfOrderReason,
            'created_at' => $record->createdAtUtc,
        ]);

        return $record->withIdentity((int) $this->pdo->lastInsertId());
    }

    public function forAppointment(int $appointmentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT m.id, m.appointment_id, m.kind, m.occurred_at, m.recorded_by_user_id,
                    m.recorded_by_role, m.note, m.consent_id, m.resulting_status,
                    m.out_of_order_reason, m.created_at, u.name AS recorded_by_name
               FROM procedure_milestones m
               LEFT JOIN users u ON u.id = m.recorded_by_user_id
              WHERE m.appointment_id = :id
              ORDER BY m.occurred_at ASC, m.id ASC'
        );
        $statement->execute(['id' => $appointmentId]);

        return $this->rows($statement->fetchAll());
    }

    public function forAppointments(array $appointmentIds): array
    {
        $ids = array_values(array_unique(array_map(intval(...), $appointmentIds)));

        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $parameters = [];

        foreach ($ids as $index => $id) {
            $placeholders[] = ':id' . $index;
            $parameters['id' . $index] = $id;
        }

        $statement = $this->pdo->prepare(
            'SELECT m.id, m.appointment_id, m.kind, m.occurred_at, m.recorded_by_user_id,
                    m.recorded_by_role, m.note, m.consent_id, m.resulting_status,
                    m.out_of_order_reason, m.created_at, u.name AS recorded_by_name
               FROM procedure_milestones m
               LEFT JOIN users u ON u.id = m.recorded_by_user_id
              WHERE m.appointment_id IN (' . implode(',', $placeholders) . ')
              ORDER BY m.occurred_at ASC, m.id ASC'
        );
        $statement->execute($parameters);

        $grouped = [];

        foreach ($this->rows($statement->fetchAll()) as $record) {
            $grouped[$record->appointmentId][] = $record;
        }

        return $grouped;
    }

    /**
     * @param array<int, mixed> $rows
     *
     * @return list<ProcedureMilestoneRecord>
     */
    private function rows(array $rows): array
    {
        $records = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $records[] = ProcedureMilestoneRecord::fromRow($row);
            }
        }

        return $records;
    }
}
