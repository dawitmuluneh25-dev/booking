<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Actor;
use App\Domain\Referral;
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
        clinical_summary, target_site, referring_physician_name, referring_facility,
        referring_physician_phone, submitted_by_user_id, submitted_on_behalf_of, created_at';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Transaction $transaction,
    ) {
    }

    public function create(Referral $referral, Actor $actor, string $occurredAtUtc): Referral
    {
        return $this->transaction->run(
            function () use ($referral, $actor, $occurredAtUtc): Referral {
                $insert = $this->pdo->prepare(
                    'INSERT INTO referrals
                        (reference, status, version, patient_name, patient_phone, patient_dob,
                         guardian_name, guardian_phone, guardian_relationship, procedure_code,
                         requested_urgency, clinical_summary, target_site, referring_physician_name,
                         referring_facility, referring_physician_phone, submitted_by_user_id,
                         submitted_on_behalf_of, created_at, updated_at)
                     VALUES
                        (:reference, :status, 1, :patient_name, :patient_phone, :patient_dob,
                         :guardian_name, :guardian_phone, :guardian_relationship, :procedure_code,
                         :requested_urgency, :clinical_summary, :target_site, :referring_physician_name,
                         :referring_facility, :referring_physician_phone, :submitted_by_user_id,
                         :submitted_on_behalf_of, :created_at, :updated_at)'
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
                    'submitted_by_user_id' => $referral->submittedByUserId,
                    'submitted_on_behalf_of' => $referral->submittedOnBehalfOf,
                    'created_at' => $referral->createdAtUtc,
                    'updated_at' => $referral->createdAtUtc,
                ]);

                $id = (int) $this->pdo->lastInsertId();

                // The opening row records who created the request, not just that it
                // exists; a submission without its history row would break the chain.
                $this->appendHistory($id, null, $referral->status, $actor, null, $occurredAtUtc);

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

    public function transition(
        Referral $referral,
        ReferralStatus $target,
        Actor $actor,
        ?string $reason,
        string $occurredAtUtc,
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

        return $this->transaction->run(
            function () use ($referral, $target, $actor, $reason, $occurredAtUtc): Referral {
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

                return $referral->withStatus($target, $referral->version + 1);
            },
        );
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
}
