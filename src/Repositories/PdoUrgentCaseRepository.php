<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Actor;
use App\Domain\Transaction;
use App\Domain\UrgentCase;
use App\Domain\UrgentCaseRepository;
use App\Domain\UrgentContactAttempt;
use App\Domain\UrgentCoordination;
use App\Domain\UrgentStatus;
use App\Domain\UrgentTransitionException;
use PDO;

/**
 * MySQL implementation of the urgent boundary.
 *
 * Guarded updates in both directions: the urgency column and the coordination
 * column each move only from the state the caller read, so two coordinators
 * working the same case cannot overwrite one another.
 */
final class PdoUrgentCaseRepository implements UrgentCaseRepository
{
    private const COLUMNS = 'id, reference, urgency_status, coordination_status, version,
        procedure_code, patient_name, patient_dob, patient_age_months, guardian_name,
        guardian_phone, guardian_relationship, referring_user_id, referring_physician_name,
        referring_facility, referring_physician_phone, receiving_hospital_name,
        clinical_summary, imaging_summary, received_at, created_at';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Transaction $transaction,
    ) {
    }

    public function create(UrgentCase $case, Actor $actor, string $occurredAtUtc): UrgentCase
    {
        return $this->transaction->run(
            function () use ($case, $actor, $occurredAtUtc): UrgentCase {
                $insert = $this->pdo->prepare(
                    'INSERT INTO urgent_cases
                        (reference, procedure_code, urgency_status, coordination_status, version,
                         patient_name, patient_dob, patient_age_months, guardian_name,
                         guardian_phone, guardian_relationship, referring_user_id,
                         referring_physician_name, referring_facility, referring_physician_phone,
                         receiving_hospital_name, clinical_summary, imaging_summary,
                         received_at, created_at, updated_at)
                     VALUES
                        (:reference, :procedure_code, :urgency, :coordination, 1,
                         :patient_name, :patient_dob, :patient_age_months, :guardian_name,
                         :guardian_phone, :guardian_relationship, :referring_user_id,
                         :referring_physician_name, :referring_facility, :referring_physician_phone,
                         :receiving_hospital_name, :clinical_summary, :imaging_summary,
                         :received_at, :created_at, :updated_at)'
                );

                $insert->execute([
                    'reference' => $case->reference,
                    'procedure_code' => $case->procedureCode,
                    'urgency' => $case->urgency->value,
                    'coordination' => $case->coordination->value,
                    'patient_name' => $case->patientName,
                    'patient_dob' => $case->patientDob,
                    'patient_age_months' => $case->patientAgeMonths,
                    'guardian_name' => $case->guardianName,
                    'guardian_phone' => $case->guardianPhone,
                    'guardian_relationship' => $case->guardianRelationship,
                    'referring_user_id' => $case->referringUserId,
                    'referring_physician_name' => $case->referringPhysicianName,
                    'referring_facility' => $case->referringFacility,
                    'referring_physician_phone' => $case->referringPhysicianPhone,
                    'receiving_hospital_name' => $case->receivingHospitalName,
                    'clinical_summary' => $case->clinicalSummary,
                    'imaging_summary' => $case->imagingSummary,
                    'received_at' => $case->receivedAtUtc,
                    'created_at' => $case->createdAtUtc,
                    'updated_at' => $case->createdAtUtc,
                ]);

                $id = (int) $this->pdo->lastInsertId();
                $this->appendHistory($id, null, $case->urgency, $actor, null, $occurredAtUtc);

                return $case->withIdentity($id);
            },
        );
    }

    public function findById(int $id): ?UrgentCase
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM urgent_cases WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : UrgentCase::fromRow($row);
    }

    public function transitionUrgency(
        UrgentCase $case,
        UrgentStatus $target,
        Actor $actor,
        ?string $reason,
        string $occurredAtUtc,
    ): UrgentCase {
        if (!$case->urgency->canTransitionTo($target)) {
            throw new UrgentTransitionException($case->urgency->value, $target->value);
        }

        if (!in_array($actor->role, $case->urgency->allowedActors($target), true)) {
            throw UrgentTransitionException::forActor($case->urgency, $target, $actor->role);
        }

        if ($case->urgency->requiresReason($target) && ($reason === null || trim($reason) === '')) {
            throw UrgentTransitionException::forMissingReason($case->urgency, $target);
        }

        return $this->transaction->run(
            function () use ($case, $target, $actor, $reason, $occurredAtUtc): UrgentCase {
                $update = $this->pdo->prepare(
                    'UPDATE urgent_cases
                        SET urgency_status = :target, version = version + 1, updated_at = :updated_at
                      WHERE id = :id AND version = :version AND urgency_status = :expected'
                );
                $update->execute([
                    'target' => $target->value,
                    'updated_at' => $occurredAtUtc,
                    'id' => $case->id,
                    'version' => $case->version,
                    'expected' => $case->urgency->value,
                ]);

                if ($update->rowCount() !== 1) {
                    throw new UrgentTransitionException(
                        $case->urgency->value,
                        $target->value,
                        'The case changed while you were working on it. Reload and try again.',
                    );
                }

                $this->appendHistory($case->id, $case->urgency, $target, $actor, $reason, $occurredAtUtc);

                return $case->withUrgency($target, $case->version + 1);
            },
        );
    }

    public function acknowledge(
        UrgentCase $case,
        Actor $actor,
        string $channel,
        ?string $note,
        string $occurredAtUtc,
    ): UrgentCase {
        $target = UrgentCoordination::Acknowledged;

        if (!$case->coordination->canTransitionTo($target)) {
            throw UrgentTransitionException::forCoordination(
                $case->coordination,
                $target,
                'This case has already been acknowledged.',
            );
        }

        if (!in_array($actor->role, $case->coordination->allowedActors($target), true)) {
            throw UrgentTransitionException::forCoordination(
                $case->coordination,
                $target,
                sprintf('The %s role may not acknowledge this case.', $actor->role->value),
            );
        }

        return $this->transaction->run(
            function () use ($case, $actor, $channel, $note, $target, $occurredAtUtc): UrgentCase {
                $insert = $this->pdo->prepare(
                    'INSERT INTO urgent_acknowledgements
                        (urgent_case_id, acknowledged_by_user_id, acknowledgement_channel, note, acknowledged_at)
                     VALUES (:case_id, :user_id, :channel, :note, :acknowledged_at)'
                );
                $insert->execute([
                    'case_id' => $case->id,
                    'user_id' => $actor->userId,
                    'channel' => $channel,
                    'note' => $note,
                    'acknowledged_at' => $occurredAtUtc,
                ]);

                $update = $this->pdo->prepare(
                    'UPDATE urgent_cases
                        SET coordination_status = :target, version = version + 1, updated_at = :updated_at
                      WHERE id = :id AND version = :version AND coordination_status = :expected'
                );
                $update->execute([
                    'target' => $target->value,
                    'updated_at' => $occurredAtUtc,
                    'id' => $case->id,
                    'version' => $case->version,
                    'expected' => $case->coordination->value,
                ]);

                if ($update->rowCount() !== 1) {
                    throw UrgentTransitionException::forCoordination(
                        $case->coordination,
                        $target,
                        'Another person acknowledged this case first.',
                    );
                }

                return $case->withCoordination($target, $case->version + 1);
            },
        );
    }

    public function recordContactAttempt(
        UrgentCase $case,
        UrgentContactAttempt $attempt,
    ): UrgentCase {
        $target = UrgentCoordination::Contacting;
        $moves = $case->coordination->canTransitionTo($target);

        if (!$moves && $case->coordination !== UrgentCoordination::Contacting) {
            throw UrgentTransitionException::forCoordination(
                $case->coordination,
                $target,
                'This case is ' . $case->coordination->value . ' and cannot take a new contact attempt.',
            );
        }

        return $this->transaction->run(
            function () use ($case, $attempt, $target, $moves): UrgentCase {
                $insert = $this->pdo->prepare(
                    'INSERT INTO urgent_contact_attempts
                        (urgent_case_id, attempted_by_user_id, contact_role, contact_name,
                         contact_phone, channel, outcome, notes, attempted_at)
                     VALUES
                        (:case_id, :user_id, :contact_role, :contact_name,
                         :contact_phone, :channel, :outcome, :notes, :attempted_at)'
                );
                $insert->execute([
                    'case_id' => $case->id,
                    'user_id' => $attempt->attemptedByUserId,
                    'contact_role' => $attempt->contactRole,
                    'contact_name' => $attempt->contactName,
                    'contact_phone' => $attempt->contactPhone,
                    'channel' => $attempt->channel,
                    'outcome' => $attempt->outcome,
                    'notes' => $attempt->notes,
                    'attempted_at' => $attempt->attemptedAtUtc,
                ]);

                if (!$moves) {
                    return $case;
                }

                $update = $this->pdo->prepare(
                    'UPDATE urgent_cases
                        SET coordination_status = :target, version = version + 1, updated_at = :updated_at
                      WHERE id = :id AND version = :version AND coordination_status = :expected'
                );
                $update->execute([
                    'target' => $target->value,
                    'updated_at' => $attempt->attemptedAtUtc,
                    'id' => $case->id,
                    'version' => $case->version,
                    'expected' => $case->coordination->value,
                ]);

                if ($update->rowCount() !== 1) {
                    throw UrgentTransitionException::forCoordination(
                        $case->coordination,
                        $target,
                        'Another person updated this case first.',
                    );
                }

                return $case->withCoordination($target, $case->version + 1);
            },
        );
    }

    public function escalate(
        UrgentCase $case,
        Actor $actor,
        int $level,
        string $reason,
        ?int $assignToUserId,
        string $occurredAtUtc,
    ): UrgentCase {
        $target = UrgentCoordination::Escalated;
        $moves = $case->coordination->canTransitionTo($target);

        // Escalating an already escalated case adds a row without moving it: the
        // second escalation is a fact about the case, not a new state.
        if (!$moves && $case->coordination !== UrgentCoordination::Escalated) {
            throw UrgentTransitionException::forCoordination(
                $case->coordination,
                $target,
                'This case is ' . $case->coordination->value . ' and cannot be escalated.',
            );
        }

        if (!in_array($actor->role, $case->coordination->escalationActors(), true)) {
            throw UrgentTransitionException::forCoordination(
                $case->coordination,
                $target,
                sprintf('The %s role may not escalate this case.', $actor->role->value),
            );
        }

        return $this->transaction->run(
            function () use (
                $case,
                $actor,
                $level,
                $reason,
                $assignToUserId,
                $target,
                $moves,
                $occurredAtUtc,
            ): UrgentCase {
                $insert = $this->pdo->prepare(
                    'INSERT INTO urgent_escalations
                        (urgent_case_id, escalated_by_user_id, assigned_to_user_id,
                         escalation_level, reason, escalated_at)
                     VALUES
                        (:case_id, :user_id, :assignee, :level, :reason, :escalated_at)'
                );
                $insert->execute([
                    'case_id' => $case->id,
                    'user_id' => $actor->userId,
                    'assignee' => $assignToUserId,
                    'level' => $level,
                    'reason' => $reason,
                    'escalated_at' => $occurredAtUtc,
                ]);

                if (!$moves) {
                    return $case;
                }

                $update = $this->pdo->prepare(
                    'UPDATE urgent_cases
                        SET coordination_status = :target, version = version + 1, updated_at = :updated_at
                      WHERE id = :id AND version = :version AND coordination_status = :expected'
                );
                $update->execute([
                    'target' => $target->value,
                    'updated_at' => $occurredAtUtc,
                    'id' => $case->id,
                    'version' => $case->version,
                    'expected' => $case->coordination->value,
                ]);

                if ($update->rowCount() !== 1) {
                    throw UrgentTransitionException::forCoordination(
                        $case->coordination,
                        $target,
                        'Another person updated this case first.',
                    );
                }

                return $case->withCoordination($target, $case->version + 1);
            },
        );
    }

    private function appendHistory(
        int $caseId,
        ?UrgentStatus $from,
        UrgentStatus $to,
        Actor $actor,
        ?string $reason,
        string $occurredAtUtc,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO urgent_status_history
                (urgent_case_id, from_status, to_status, actor_user_id, actor_role, reason, occurred_at)
             VALUES (:case_id, :from_status, :to_status, :actor_user_id, :actor_role, :reason, :occurred_at)'
        );
        $statement->execute([
            'case_id' => $caseId,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_user_id' => $actor->userId,
            'actor_role' => $actor->role->value,
            'reason' => $reason,
            'occurred_at' => $occurredAtUtc,
        ]);
    }
}
