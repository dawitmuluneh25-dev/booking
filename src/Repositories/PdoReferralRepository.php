<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Actor;
use App\Domain\ClinicalDecision;
use App\Domain\ClinicalDecisionRecord;
use App\Domain\ClinicalDecisionType;
use App\Domain\ClinicalPriorityChange;
use App\Domain\ConsentNotPermittedException;
use App\Domain\OutboundMessage;
use App\Domain\Referral;
use App\Domain\ReferralNotFoundException;
use App\Domain\ReferralRepository;
use App\Domain\ReferralStatus;
use App\Domain\ReferralTransitionException;
use App\Domain\Transaction;
use PDO;

/**
 * MySQL implementation of the referral boundary.
 *
 * Two guarantees:
 *
 * 1. Atomicity comes from the shared unit of work, not from opening a private
 *    transaction here. A command that converts a referral can therefore write the
 *    referral and the appointment in one transaction that either commits or does
 *    not, and a caller that uses this repository alone still gets both the row and
 *    its history row.
 *
 * 2. The transition is guarded by `WHERE id = ? AND version = ? AND status = ?`
 *    and insists on exactly one affected row. If another request advanced the
 *    referral between the read and the write, zero rows change and the caller is
 *    refused instead of overwriting someone else's decision.
 */
final class PdoReferralRepository implements ReferralRepository
{
    private const COLUMNS = 'id, reference, status, version, patient_name, patient_phone, patient_dob,
        guardian_name, guardian_phone, guardian_relationship, procedure_code, requested_urgency,
        clinical_priority, clinical_summary, target_site, referring_physician_name, referring_facility,
        referring_physician_phone, referring_physician_profile_id, submitted_by_user_id,
        submitted_on_behalf_of, created_at';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Transaction $transaction,
    ) {
    }

    /** @param list<OutboundMessage> $messages */
    public function create(
        Referral $referral,
        Actor $actor,
        string $occurredAtUtc,
        array $messages = [],
    ): Referral {
        return $this->transaction->run(
            function () use ($referral, $actor, $occurredAtUtc, $messages): Referral {
                $insert = $this->pdo->prepare(
                    'INSERT INTO referrals
                        (reference, status, version, patient_name, patient_phone, patient_dob,
                         guardian_name, guardian_phone, guardian_relationship, procedure_code,
                         requested_urgency, clinical_summary, target_site, referring_physician_name,
                         referring_facility, referring_physician_phone,
                         referring_physician_profile_id, submitted_by_user_id,
                         submitted_on_behalf_of, created_at, updated_at,
                         patient_age_value, patient_age_unit, patient_age_source,
                         patient_identifier, patient_identifier_type, patient_identifier_facility,
                         patient_email, preferred_language, referring_physician_credentials,
                         referring_department, clinical_question)
                     VALUES
                        (:reference, :status, 1, :patient_name, :patient_phone, :patient_dob,
                         :guardian_name, :guardian_phone, :guardian_relationship, :procedure_code,
                         :requested_urgency, :clinical_summary, :target_site, :referring_physician_name,
                         :referring_facility, :referring_physician_phone,
                         :physician_profile, :submitted_by_user_id,
                         :submitted_on_behalf_of, :created_at, :updated_at,
                         :patient_age_value, :patient_age_unit, :patient_age_source,
                         :patient_identifier, :patient_identifier_type, :patient_identifier_facility,
                         :patient_email, :preferred_language, :referring_physician_credentials,
                         :referring_department, :clinical_question)'
                );

                $insert->execute([
                    'reference' => $referral->reference,
                    'status' => $referral->status->value,
                    'patient_name' => $referral->patientName,
                    'patient_phone' => $referral->patientPhone,
                    'patient_dob' => $referral->patientDob,
                    'guardian_name' => $referral->guardianName,
                    'guardian_phone' => $referral->guardianPhone,
                    'guardian_relationship' => $referral->guardianRelationship,
                    'procedure_code' => $referral->procedureCode,
                    'requested_urgency' => $referral->requestedUrgency,
                    'clinical_summary' => $referral->clinicalSummary,
                    'target_site' => $referral->targetSite,
                    'referring_physician_name' => $referral->referringPhysicianName,
                    'referring_facility' => $referral->referringFacility,
                    'referring_physician_phone' => $referral->referringPhysicianPhone,
                    'physician_profile' => $referral->referringPhysicianProfileId,
                    'submitted_by_user_id' => $referral->submittedByUserId,
                    'submitted_on_behalf_of' => $referral->submittedOnBehalfOf,
                    'created_at' => $referral->createdAtUtc,
                    'updated_at' => $referral->createdAtUtc,
                    'patient_age_value' => $referral->patientAgeValue,
                    'patient_age_unit' => $referral->patientAgeUnit,
                    'patient_age_source' => $referral->patientAgeSource,
                    'patient_identifier' => $referral->patientIdentifier,
                    'patient_identifier_type' => $referral->patientIdentifierType,
                    'patient_identifier_facility' => $referral->patientIdentifierFacility,
                    'patient_email' => $referral->patientEmail,
                    'preferred_language' => $referral->preferredLanguage,
                    'referring_physician_credentials' => $referral->referringPhysicianCredentials,
                    'referring_department' => $referral->referringDepartment,
                    'clinical_question' => $referral->clinicalQuestion,
                ]);

                $id = (int) $this->pdo->lastInsertId();

                // The opening row records who created the request, not just that it
                // exists; a submission without its history row would break the chain.
                $this->appendHistory($id, null, $referral->status, $actor, null, $occurredAtUtc);
                $this->enqueue($id, $messages);

                return $referral->withIdentity($id);
            },
        );
    }

    public function findByReference(string $reference): ?Referral
    {
        return $this->findOne('reference = :value', $reference);
    }

    public function findById(int $id): ?Referral
    {
        return $this->findOne('id = :value', (string) $id);
    }

    /** @return list<Referral> */
    public function referredBy(int $physicianProfileId, int $limit = 50): array
    {
        // Newest first: a referrer opens this page to see how the case they sent
        // yesterday is doing, not the one from last year.
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM referrals
              WHERE referring_physician_profile_id = :profile
              ORDER BY created_at DESC, id DESC
              LIMIT :limit'
        );
        $statement->bindValue('profile', $physicianProfileId, PDO::PARAM_INT);
        $statement->bindValue('limit', max(1, min($limit, 200)), PDO::PARAM_INT);
        $statement->execute();

        $referrals = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $referrals[] = Referral::fromRow($row);
            }
        }

        return $referrals;
    }

    public function outstandingInformation(int $referralId): ?string
    {
        // Reception's request lives on the transition, because that is where the
        // desk says why it is sending the case back. The newest one wins: an earlier
        // answer is history once the referral has been back and out again.
        $statement = $this->pdo->prepare(
            "SELECT h.reason FROM referral_status_history h
              WHERE h.referral_id = :referral_id AND h.to_status = 'awaiting_information'
              ORDER BY h.occurred_at DESC, h.id DESC
              LIMIT 1"
        );
        $statement->execute(['referral_id' => $referralId]);

        $reason = $statement->fetchColumn();

        if (is_scalar($reason) && trim((string) $reason) !== '') {
            return trim((string) $reason);
        }

        // Empty here does not mean nothing was asked. A radiologist recording
        // `more_information` moves the referral with no reason (the outcome does not
        // require one), so the question is on the decision and nowhere else.
        $decision = $this->currentDecision($referralId);

        if ($decision === null || $decision->type !== ClinicalDecisionType::MoreInformation) {
            return null;
        }

        return $decision->informationRequested;
    }

    /** @param list<OutboundMessage> $messages */
    public function transition(
        Referral $referral,
        ReferralStatus $target,
        Actor $actor,
        ?string $reason,
        string $occurredAtUtc,
        array $messages = [],
    ): Referral {
        // The command has already checked these, but the repository is the last
        // thing between a caller and the table, so it refuses on its own rather
        // than trusting every future caller to have asked (WFS 5.3, WF-08).
        if (!$referral->status->canTransitionTo($target)) {
            throw new ReferralTransitionException($referral->status, $target);
        }

        if (!in_array($actor->role, $referral->status->allowedActors($target), true)) {
            throw ReferralTransitionException::forActor($referral->status, $target, $actor->role);
        }

        if ($referral->status->requiresReason($target) && ($reason === null || trim($reason) === '')) {
            throw ReferralTransitionException::forMissingReason($referral->status, $target);
        }

        return $this->transaction->run(
            function () use ($referral, $target, $actor, $reason, $occurredAtUtc, $messages): Referral {
                $sets = [
                    'status = :target',
                    'version = version + 1',
                    'updated_at = :updated_at',
                ];
                $parameters = [
                    'target' => $target->value,
                    'updated_at' => $occurredAtUtc,
                    'id' => $referral->id,
                    'version' => $referral->version,
                    'expected' => $referral->status->value,
                ];

                $stamp = match ($target) {
                    ReferralStatus::Received => 'received_at',
                    ReferralStatus::ClinicallyApproved => 'approved_at',
                    ReferralStatus::Cancelled => 'cancelled_at',
                    ReferralStatus::Closed => 'closed_at',
                    default => null,
                };

                if ($stamp !== null) {
                    // The column name comes from the match above, never from input.
                    $sets[] = $stamp . ' = :stamp_at';
                    $parameters['stamp_at'] = $occurredAtUtc;
                }

                $update = $this->pdo->prepare(
                    'UPDATE referrals SET ' . implode(', ', $sets)
                    . ' WHERE id = :id AND version = :version AND status = :expected'
                );
                $update->execute($parameters);

                if ($update->rowCount() !== 1) {
                    // Either the version moved on or the status was not what we read.
                    throw new ReferralTransitionException(
                        $referral->status,
                        $target,
                        'The referral changed while you were working on it. Reload and try again.',
                    );
                }

                $this->appendHistory(
                    $referral->id,
                    $referral->status,
                    $target,
                    $actor,
                    $reason,
                    $occurredAtUtc,
                );

                $this->enqueue($referral->id, $messages);

                return $referral->withStatus($target, $referral->version + 1);
            },
        );
    }

    /** @return list<Referral> */
    public function approvedForScheduling(int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . " FROM referrals
              WHERE status = 'approved_for_scheduling'
              ORDER BY updated_at, id
              LIMIT :limit"
        );
        $statement->bindValue('limit', max(1, min($limit, 200)), PDO::PARAM_INT);
        $statement->execute();

        $referrals = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $referrals[] = Referral::fromRow($row);
            }
        }

        return $referrals;
    }
    public function currentDecision(int $referralId): ?ClinicalDecision
    {
        // "Not superseded" beats "newest", because a correction is not always the
        // later row and the standing decision is the one nobody replaced.
        $statement = $this->pdo->prepare(
            'SELECT d.* FROM clinical_decisions d
              WHERE d.referral_id = :referral_id
                AND NOT EXISTS (
                    SELECT 1 FROM clinical_decisions s WHERE s.supersedes_decision_id = d.id
                )
              ORDER BY d.decided_at DESC, d.id DESC
              LIMIT 1'
        );
        $statement->execute(['referral_id' => $referralId]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : ClinicalDecision::fromRow($row);
    }

    /** @return list<ClinicalDecisionRecord> */
    public function decisionHistory(int $referralId): array
    {
        // The join to `users` is for a reader, not for the record: the row keeps
        // the id, so renaming an account does not rewrite who decided what.
        $statement = $this->pdo->prepare(
            'SELECT d.*, u.name AS reviewer_name, s.id AS superseded_by_id
               FROM clinical_decisions d
               LEFT JOIN users u ON u.id = d.decided_by_user_id
               LEFT JOIN clinical_decisions s ON s.supersedes_decision_id = d.id
              WHERE d.referral_id = :referral_id
              ORDER BY d.decided_at ASC, d.id ASC'
        );
        $statement->execute(['referral_id' => $referralId]);

        $records = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $records[] = ClinicalDecisionRecord::fromRow($row);
            }
        }

        return $records;
    }

    public function recordDecision(ClinicalDecision $decision): ClinicalDecision
    {
        return $this->transaction->run(
            function () use ($decision): ClinicalDecision {
                $insert = $this->pdo->prepare(
                    'INSERT INTO clinical_decisions
                        (referral_id, decision_type, rationale, clinical_priority,
                         approved_technique, modality, sedation, estimated_duration_minutes,
                         preprocedure_conditions, redirect_destination, information_requested,
                         decided_by_user_id, decided_at, supersedes_decision_id)
                     VALUES
                        (:referral_id, :decision_type, :rationale, :clinical_priority,
                         :technique, :modality, :sedation, :duration, :preprocedure, :redirect,
                         :information, :decided_by, :decided_at, :supersedes)'
                );
                $insert->execute([
                    'referral_id' => $decision->referralId,
                    'decision_type' => $decision->type->value,
                    'rationale' => $decision->rationale,
                    'clinical_priority' => $decision->clinicalPriority,
                    'technique' => $decision->technique,
                    'modality' => $decision->modality,
                    'sedation' => $decision->sedation,
                    'duration' => $decision->estimatedDurationMinutes,
                    'preprocedure' => $decision->preprocedureConditions,
                    'redirect' => $decision->redirectDestination,
                    'information' => $decision->informationRequested,
                    'decided_by' => $decision->decidedByUserId,
                    'decided_at' => $decision->decidedAtUtc,
                    'supersedes' => $decision->supersedesId,
                ]);

                return $decision->withIdentity((int) $this->pdo->lastInsertId());
            },
        );
    }

    public function assignClinicalPriority(int $referralId, string $priority): void
    {
        // No version guard: this is not a state transition and it does not race a
        // reader into a decision. Two clinicians assigning at once is resolved by
        // the decision rows, which both remain, and the last assignment stands.
        $update = $this->pdo->prepare(
            'UPDATE referrals SET clinical_priority = :priority, updated_at = UTC_TIMESTAMP()
              WHERE id = :id'
        );
        $update->execute(['priority' => $priority, 'id' => $referralId]);

        if ($update->rowCount() === 0) {
            // Zero affected rows means either "no such referral" or "the value was
            // already this one", and MySQL reports both the same way. Ask rather
            // than assume, so re-assigning the same category is not an error.
            $exists = $this->pdo->prepare('SELECT 1 FROM referrals WHERE id = :id');
            $exists->execute(['id' => $referralId]);

            if ($exists->fetchColumn() === false) {
                throw new ReferralNotFoundException('#' . $referralId);
            }
        }
    }

    public function recordPriorityChange(ClinicalPriorityChange $change): ClinicalPriorityChange
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO referral_priority_changes
                (referral_id, from_priority, to_priority, reason, changed_by_user_id, changed_at)
             VALUES (:referral_id, :from_priority, :to_priority, :reason, :changed_by, :changed_at)'
        );
        $statement->execute([
            'referral_id' => $change->referralId,
            'from_priority' => $change->fromPriority,
            'to_priority' => $change->toPriority,
            'reason' => $change->reason,
            'changed_by' => $change->changedByUserId,
            'changed_at' => $change->changedAtUtc,
        ]);

        return new ClinicalPriorityChange(
            id: (int) $this->pdo->lastInsertId(),
            referralId: $change->referralId,
            fromPriority: $change->fromPriority,
            toPriority: $change->toPriority,
            reason: $change->reason,
            changedByUserId: $change->changedByUserId,
            changedAtUtc: $change->changedAtUtc,
        );
    }

    /** @return list<array{change: ClinicalPriorityChange, changedBy: string}> */
    public function priorityChangeHistory(int $referralId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.*, u.name AS changed_by_name
               FROM referral_priority_changes p
               JOIN users u ON u.id = p.changed_by_user_id
              WHERE p.referral_id = :referral_id
              ORDER BY p.changed_at ASC, p.id ASC'
        );
        $statement->execute(['referral_id' => $referralId]);

        $history = [];

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = $row['changed_by_name'] ?? '';

            $history[] = [
                'change' => ClinicalPriorityChange::fromRow($row),
                'changedBy' => is_scalar($name) ? (string) $name : '',
            ];
        }

        return $history;
    }

    /** @return list<Referral> */
    public function queued(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . " FROM referrals
             WHERE status IN ('submitted', 'received', 'administrative_review', 'awaiting_information')
             ORDER BY created_at ASC, id ASC
             LIMIT :limit"
        );
        $statement->bindValue('limit', max(1, min($limit, 200)), PDO::PARAM_INT);
        $statement->execute();

        $referrals = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $referrals[] = Referral::fromRow($row);
            }
        }

        return $referrals;
    }
    /** @return list<Referral> */
    public function awaitingClinicalReview(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . " FROM referrals
             WHERE status = 'clinical_review'
             ORDER BY created_at ASC, id ASC
             LIMIT :limit"
        );
        $statement->bindValue('limit', max(1, min($limit, 200)), PDO::PARAM_INT);
        $statement->execute();

        $referrals = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $referrals[] = Referral::fromRow($row);
            }
        }

        return $referrals;
    }
    private function findOne(string $condition, string $value): ?Referral
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM referrals WHERE ' . $condition . ' LIMIT 1'
        );
        $statement->execute(['value' => $value]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : Referral::fromRow($row);
    }

    /**
     * Insert the notifications inside the caller's transaction. `INSERT IGNORE`
     * plus the unique key on (referral_id, template, channel) makes enqueueing
     * idempotent, so a retried submission does not send twice.
     *
     * @param list<OutboundMessage> $messages
     */
    private function enqueue(int $referralId, array $messages): void
    {
        // One writer for every outbox insert, so the dedupe key cannot be
        // forgotten by the next subject that starts sending messages.
        (new OutboxWriter($this->pdo))->write(null, $referralId, $messages);
    }

    private function appendHistory(
        int $referralId,
        ?ReferralStatus $from,
        ReferralStatus $to,
        Actor $actor,
        ?string $reason,
        string $occurredAtUtc,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO referral_status_history
                (referral_id, from_status, to_status, actor_user_id, actor_role, reason, occurred_at)
             VALUES
                (:referral_id, :from_status, :to_status, :actor_user_id, :actor_role, :reason, :occurred_at)'
        );

        $statement->execute([
            'referral_id' => $referralId,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_user_id' => $actor->userId,
            'actor_role' => $actor->role->value,
            'reason' => $reason,
            'occurred_at' => $occurredAtUtc,
        ]);
    }

    /** @return array{at: string, basis: string, by: string}|null */
    public function guardianVerification(int $referralId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.guardian_verified_at, r.guardian_verification_basis, u.name AS verifier
               FROM referrals r
               LEFT JOIN users u ON u.id = r.guardian_verified_by_user_id
              WHERE r.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $referralId]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        if ($row === false || !is_scalar($row['guardian_verified_at'] ?? null)) {
            return null;
        }

        $at = (string) $row['guardian_verified_at'];

        if ($at === '') {
            return null;
        }

        $basis = $row['guardian_verification_basis'] ?? null;
        $verifier = $row['verifier'] ?? null;

        return [
            'at' => $at,
            'basis' => is_scalar($basis) ? (string) $basis : '',
            'by' => is_scalar($verifier) ? (string) $verifier : 'Unknown user',
        ];
    }

    public function verifyGuardianAuthority(
        int $referralId,
        Actor $actor,
        string $basis,
        string $occurredAtUtc,
    ): void {
        // The guard is in the WHERE clause rather than in a preceding SELECT: the
        // fact being written is "this has now happened", and reading first would let
        // two staff members both see "not yet" and both write.
        $statement = $this->pdo->prepare(
            'UPDATE referrals
                SET guardian_verified_at = :verified_at,
                    guardian_verified_by_user_id = :verified_by,
                    guardian_verification_basis = :basis,
                    version = version + 1,
                    updated_at = :updated_at
              WHERE id = :id AND guardian_verified_at IS NULL AND guardian_name IS NOT NULL'
        );
        $statement->execute([
            'verified_at' => $occurredAtUtc,
            'verified_by' => $actor->userId,
            'basis' => $basis,
            'updated_at' => $occurredAtUtc,
            'id' => $referralId,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new ConsentNotPermittedException(
                null,
                'This guardian was already verified, or the referral no longer names one.',
            );
        }
    }
}
