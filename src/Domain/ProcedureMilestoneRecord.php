<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One recorded procedure-day event.
 *
 * Immutable, like the row: there is no `with…()` that changes what happened.
 * `resultingStatus` is the state this milestone produced, when it produced one, and
 * exists so the page can print one timeline instead of printing the same minute
 * twice — once as a state change and once as the event that caused it.
 */
final class ProcedureMilestoneRecord
{
    public function __construct(
        public readonly int $id,
        public readonly int $appointmentId,
        public readonly ProcedureMilestone $kind,
        public readonly string $occurredAtUtc,
        public readonly int $recordedByUserId,
        public readonly Role $recordedByRole,
        public readonly ?string $note,
        public readonly ?int $consentId,
        public readonly ?AppointmentStatus $resultingStatus,
        public readonly ?string $outOfOrderReason,
        public readonly string $createdAtUtc,
        /**
         * The recorder's name, resolved at read time.
         *
         * Null when the record has not been read back from the database — a
         * practitioner who changes their name has not changed what they recorded,
         * so the name is joined rather than copied onto the row.
         */
        public readonly ?string $recordedByName = null,
    ) {
    }

    public function withIdentity(int $id): self
    {
        return new self(
            id: $id,
            appointmentId: $this->appointmentId,
            kind: $this->kind,
            occurredAtUtc: $this->occurredAtUtc,
            recordedByUserId: $this->recordedByUserId,
            recordedByRole: $this->recordedByRole,
            note: $this->note,
            consentId: $this->consentId,
            resultingStatus: $this->resultingStatus,
            outOfOrderReason: $this->outOfOrderReason,
            createdAtUtc: $this->createdAtUtc,
            recordedByName: $this->recordedByName,
        );
    }

    public function wasRecordedOutOfOrder(): bool
    {
        return $this->outOfOrderReason !== null;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $status = $row['resulting_status'] ?? null;

        return new self(
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            appointmentId: is_numeric($row['appointment_id'] ?? null) ? (int) $row['appointment_id'] : 0,
            kind: ProcedureMilestone::from(
                is_scalar($row['kind'] ?? null) ? (string) $row['kind'] : 'arrival',
            ),
            occurredAtUtc: is_scalar($row['occurred_at'] ?? null) ? (string) $row['occurred_at'] : '',
            recordedByUserId: is_numeric($row['recorded_by_user_id'] ?? null)
                ? (int) $row['recorded_by_user_id']
                : 0,
            recordedByRole: Role::from(
                is_scalar($row['recorded_by_role'] ?? null) ? (string) $row['recorded_by_role'] : 'staff',
            ),
            note: is_scalar($row['note'] ?? null) ? (string) $row['note'] : null,
            consentId: is_numeric($row['consent_id'] ?? null) ? (int) $row['consent_id'] : null,
            resultingStatus: is_scalar($status) ? AppointmentStatus::from((string) $status) : null,
            outOfOrderReason: is_scalar($row['out_of_order_reason'] ?? null)
                ? (string) $row['out_of_order_reason']
                : null,
            createdAtUtc: is_scalar($row['created_at'] ?? null) ? (string) $row['created_at'] : '',
            recordedByName: is_scalar($row['recorded_by_name'] ?? null)
                ? (string) $row['recorded_by_name']
                : null,
        );
    }
}
