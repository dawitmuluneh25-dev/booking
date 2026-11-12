<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Actor;
use App\Domain\Transaction;
use App\Domain\UrgentCase;
use App\Domain\UrgentCaseRepository;
use App\Domain\UrgentContactAttempt;
use App\Domain\UrgentCoordination;
use App\Domain\UrgentDecision;
use App\Domain\UrgentStatus;
use App\Domain\UrgentTimelineEntry;
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

    public function findByReference(string $reference): ?UrgentCase
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM urgent_cases WHERE reference = :reference LIMIT 1'
        );
        $statement->execute(['reference' => $reference]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : UrgentCase::fromRow($row);
    }

    public function openCases(int $limit = 50): array
    {
        // The boolean expression sorts unacknowledged to the top without a second
        // column or a CASE the optimiser has to reason about, and received_at keeps
        // the oldest at the front of each group.
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM urgent_cases
              WHERE urgency_status <> :closed
              ORDER BY (coordination_status = :unacknowledged) DESC, received_at ASC, id ASC
              LIMIT ' . max(1, min($limit, 200))
        );
        $statement->execute([
            'closed' => UrgentStatus::Closed->value,
            'unacknowledged' => UrgentCoordination::Unacknowledged->value,
        ]);

        $cases = [];

        while (($row = $statement->fetch()) !== false) {
            /** @var array<string, mixed> $row */
            $cases[] = UrgentCase::fromRow($row);
        }

        return $cases;
    }

    public function timeline(int $caseId): array
    {
        // Five tables share one shape here: when it happened, who did it, the short
        // substance and the free text. The label is composed from columns rather
        // than joined prose so the wording can be read and changed in PHP.
        $statement = $this->pdo->prepare(
            "SELECT 'acknowledgement' AS kind, ack.acknowledged_at AS occurred_at,
                    user.name AS actor_name, ack.acknowledgement_channel AS label, ack.note AS detail
               FROM urgent_acknowledgements ack
               JOIN users user ON user.id = ack.acknowledged_by_user_id
              WHERE ack.urgent_case_id = :ack_case
             UNION ALL
             SELECT 'contact', con.attempted_at, user.name,
                    CONCAT(con.contact_role, ' · ', con.channel, ' · ', con.outcome,
                           COALESCE(CONCAT(' · ', con.contact_phone), '')),
                    con.notes
               FROM urgent_contact_attempts con
               JOIN users user ON user.id = con.attempted_by_user_id
              WHERE con.urgent_case_id = :contact_case
             UNION ALL
             SELECT 'escalation', esc.escalated_at, user.name,
                    CONCAT('level ', esc.escalation_level), esc.reason
               FROM urgent_escalations esc
               JOIN users user ON user.id = esc.escalated_by_user_id
              WHERE esc.urgent_case_id = :escalation_case
             UNION ALL
             SELECT 'event', evt.event_at, user.name,
                    CONCAT(evt.event_type, COALESCE(CONCAT(' · ', evt.hospital_or_team), '')),
                    evt.event_note
               FROM urgent_events evt
               JOIN users user ON user.id = evt.event_by_user_id
              WHERE evt.urgent_case_id = :event_case
             UNION ALL
             SELECT 'status', hist.occurred_at, user.name,
                    CONCAT(COALESCE(hist.from_status, 'new'), ' → ', hist.to_status),
                    hist.reason
               FROM urgent_status_history hist
               JOIN users user ON user.id = hist.actor_user_id
              WHERE hist.urgent_case_id = :status_case
             UNION ALL
              SELECT 'decision', dsc.decided_at, user.name,
                     dsc.decision_type,
                     dsc.rationale
                FROM urgent_decisions dsc
                JOIN users user ON user.id = dsc.decided_by_user_id
               WHERE dsc.urgent_case_id = :decision_case
              ORDER BY occurred_at ASC, kind ASC"
        );
        $statement->execute([
            'ack_case' => $caseId,
            'contact_case' => $caseId,
            'escalation_case' => $caseId,
            'event_case' => $caseId,
            'status_case' => $caseId,
            'decision_case' => $caseId,
        ]);

        $entries = [];

        while (($row = $statement->fetch()) !== false) {
            /** @var array<string, mixed> $row */
            $entries[] = new UrgentTimelineEntry(
                kind: is_scalar($row['kind'] ?? null) ? (string) $row['kind'] : '',
                occurredAtUtc: is_scalar($row['occurred_at'] ?? null) ? (string) $row['occurred_at'] : '',
                actorName: is_scalar($row['actor_name'] ?? null) ? (string) $row['actor_name'] : 'Unknown user',
                label: is_scalar($row['label'] ?? null) ? (string) $row['label'] : '',
                detail: is_scalar($row['detail'] ?? null) && (string) $row['detail'] !== ''
                    ? (string) $row['detail']
                    : null,
            );
        }

        return $entries;
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
                // `closed_at` is written by the same statement as the status, so a
                // row cannot read `closed` while claiming not to know when. It is
                // the only column on this table that only one transition sets.
                $closing = $target === UrgentStatus::Closed;

                $update = $this->pdo->prepare(
                    'UPDATE urgent_cases
                        SET urgency_status = :target, version = version + 1, updated_at = :updated_at'
                    . ($closing ? ', closed_at = :closed_at' : '') .
                    ' WHERE id = :id AND version = :version AND urgency_status = :expected'
                );

                $parameters = [
                    'target' => $target->value,
                    'updated_at' => $occurredAtUtc,
                    'id' => $case->id,
                    'version' => $case->version,
                    'expected' => $case->urgency->value,
                ];

                if ($closing) {
                    $parameters['closed_at'] = $occurredAtUtc;
                }

                $update->execute($parameters);

                if ($update->rowCount() !== 1) {
                    throw new UrgentTransitionException(
                        $case->urgency->value,
                        $target->value,
                        'The case changed while you were working on it. Reload and try again.',
                    );
                }

                $this->appendHistory($case->id, $case->urgency, $target, $actor, $reason, $occurredAtUtc);
                $this->appendCoordinationEvent($case->id, $target, $actor, $reason, $occurredAtUtc);

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

    public function recordUrgentDecision(UrgentDecision $decision): UrgentDecision
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO urgent_decisions
                (urgent_case_id, decision_type, rationale, resulting_status,
                 decided_by_user_id, decided_at, supersedes_decision_id)
             VALUES
                (:case_id, :decision_type, :rationale, :resulting_status,
                 :decided_by, :decided_at, :supersedes)'
        );
        $statement->execute([
            'case_id' => $decision->urgentCaseId,
            'decision_type' => $decision->type->value,
            'rationale' => $decision->rationale,
            'resulting_status' => $decision->resultingStatus,
            'decided_by' => $decision->decidedByUserId,
            'decided_at' => $decision->decidedAtUtc,
            'supersedes' => $decision->supersedesId,
        ]);

        return new UrgentDecision(
            id: (int) $this->pdo->lastInsertId(),
            urgentCaseId: $decision->urgentCaseId,
            type: $decision->type,
            rationale: $decision->rationale,
            decidedByUserId: $decision->decidedByUserId,
            decidedAtUtc: $decision->decidedAtUtc,
            resultingStatus: $decision->resultingStatus,
            supersedesId: $decision->supersedesId,
        );
    }

    public function currentUrgentDecision(int $caseId): ?UrgentDecision
    {
        // The current decision is the one nothing supersedes. Asking the unique
        // key rather than ordering by `decided_at` means two decisions recorded in
        // the same second cannot both claim to be current.
        $statement = $this->pdo->prepare(
            'SELECT d.* FROM urgent_decisions d
              WHERE d.urgent_case_id = :case_id
                AND NOT EXISTS (
                    SELECT 1 FROM urgent_decisions s WHERE s.supersedes_decision_id = d.id
                )
              ORDER BY d.id DESC
              LIMIT 1'
        );
        $statement->execute(['case_id' => $caseId]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : UrgentDecision::fromRow($row);
    }

    /** @return list<array{decision: UrgentDecision, decidedBy: string, superseded: bool}> */
    public function urgentDecisionHistory(int $caseId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT d.*, u.name AS decided_by_name
               FROM urgent_decisions d
               JOIN users u ON u.id = d.decided_by_user_id
              WHERE d.urgent_case_id = :case_id
              ORDER BY d.decided_at ASC, d.id ASC'
        );
        $statement->execute(['case_id' => $caseId]);

        $history = [];
        $superseded = [];

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        foreach ($rows as $row) {
            $supersedes = is_array($row) ? ($row['supersedes_decision_id'] ?? null) : null;

            if (is_numeric($supersedes)) {
                $superseded[(int) $supersedes] = true;
            }
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $decision = UrgentDecision::fromRow($row);
            $name = $row['decided_by_name'] ?? '';

            $history[] = [
                'decision' => $decision,
                'decidedBy' => is_scalar($name) ? (string) $name : '',
                'superseded' => isset($superseded[$decision->id]),
            ];
        }

        return $history;
    }

    /**
     * Record the coordination event a clinical move implies.
     *
     * `UrgentStatus::occurredEventType()` names the events the workflow documents
     * require at a move — the review happened, a transfer was arranged, an outcome
     * was recorded — and until now nothing wrote them, so the timeline §10.3 asks
     * for had no rows. The status trail says the case moved; this says what
     * coordination happened when it did.
     *
     * A move with no event type writes nothing: an administrative state change is
     * not a coordination act and inventing a row for it would pad the timeline.
     */
    private function appendCoordinationEvent(
        int $caseId,
        UrgentStatus $target,
        Actor $actor,
        ?string $reason,
        string $occurredAtUtc,
    ): void {
        $eventType = $target->occurredEventType();

        if ($eventType === null) {
            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO urgent_events
                (urgent_case_id, event_type, event_by_user_id, hospital_or_team, event_note, event_at)
             VALUES (:case_id, :event_type, :user_id, NULL, :note, :event_at)'
        );
        $statement->execute([
            'case_id' => $caseId,
            'event_type' => $eventType,
            'user_id' => $actor->userId,
            // `event_note` is NOT NULL: the reason where there is one, otherwise the
            // status label, which is a true statement about the move and not a guess.
            'note' => $reason !== null && trim($reason) !== '' ? $reason : $target->label(),
            'event_at' => $occurredAtUtc,
        ]);
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
