<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Actor;
use App\Domain\ConsentNotPermittedException;
use App\Domain\ConsentRepository;
use App\Domain\ProcedureConsent;
use PDO;

/**
 * MySQL implementation of the consent boundary.
 *
 * The row is written once and never rewritten except for the three withdrawal
 * columns, which `withdraw()` guards with `withdrawn_at IS NULL`: two people
 * withdrawing the same agreement must not overwrite each other's reason, and the
 * second one is told the agreement was already withdrawn rather than silently
 * replacing the account of why.
 */
final class PdoConsentRepository implements ConsentRepository
{
    private const COLUMNS = 'id, referral_id, participant_relationship, participant_name,
        authority_verified, authority_verified_by_user_id, authority_verified_at,
        capacity_assessed, capacity_note, target_site, information_version,
        information_language, discussion_record, questions_recorded, interpreter_involved,
        interpreter_language, outcome, attestation_reference, responsible_practitioner_user_id,
        recorded_by_user_id, consented_at, withdrawn_at, withdrawn_by_user_id,
        withdrawal_reason, supersedes_consent_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(ProcedureConsent $consent): ProcedureConsent
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO procedure_consents
                (referral_id, participant_relationship, participant_name, authority_verified,
                 authority_verified_by_user_id, authority_verified_at, capacity_assessed,
                 capacity_note, target_site, information_version, information_language,
                 discussion_record, questions_recorded, interpreter_involved, interpreter_language,
                 outcome, attestation_reference, responsible_practitioner_user_id,
                 recorded_by_user_id, consented_at, supersedes_consent_id, created_at)
             VALUES
                (:referral_id, :participant_relationship, :participant_name, :authority_verified,
                 :authority_verified_by, :authority_verified_at, :capacity_assessed,
                 :capacity_note, :target_site, :information_version, :information_language,
                 :discussion_record, :questions_recorded, :interpreter_involved, :interpreter_language,
                 :outcome, :attestation_reference, :responsible_practitioner,
                 :recorded_by, :consented_at, :supersedes, :created_at)'
        );

        $insert->execute([
            'referral_id' => $consent->referralId,
            'participant_relationship' => $consent->participant->value,
            'participant_name' => $consent->participantName,
            'authority_verified' => $consent->authorityVerified ? 1 : 0,
            'authority_verified_by' => $consent->authorityVerifiedByUserId,
            'authority_verified_at' => $consent->authorityVerifiedAtUtc,
            'capacity_assessed' => $consent->capacityAssessed ? 1 : 0,
            'capacity_note' => $consent->capacityNote,
            'target_site' => $consent->targetSite,
            'information_version' => $consent->informationVersion,
            'information_language' => $consent->informationLanguage,
            'discussion_record' => $consent->discussionRecord,
            'questions_recorded' => $consent->questionsRecorded,
            'interpreter_involved' => $consent->interpreterInvolved ? 1 : 0,
            'interpreter_language' => $consent->interpreterLanguage,
            'outcome' => $consent->outcome->value,
            'attestation_reference' => $consent->attestationReference,
            'responsible_practitioner' => $consent->responsiblePractitionerUserId,
            'recorded_by' => $consent->recordedByUserId,
            'consented_at' => $consent->consentedAtUtc,
            'supersedes' => $consent->supersedesId,
            'created_at' => $consent->consentedAtUtc,
        ]);

        return $consent->withIdentity((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?ProcedureConsent
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM procedure_consents WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : ProcedureConsent::fromRow($row);
    }

    public function current(int $referralId): ?ProcedureConsent
    {
        // The current record is the row nothing supersedes. The unique key on
        // `supersedes_consent_id` makes that at most one row, so ordering is only a
        // tie-break for a chain that should not be able to fork.
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM procedure_consents c
              WHERE c.referral_id = :referral_id
                AND NOT EXISTS (
                    SELECT 1 FROM procedure_consents later
                     WHERE later.supersedes_consent_id = c.id
                )
              ORDER BY c.consented_at DESC, c.id DESC
              LIMIT 1'
        );
        $statement->execute(['referral_id' => $referralId]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : ProcedureConsent::fromRow($row);
    }

    public function history(int $referralId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.*, u.name AS recorded_by_name
               FROM procedure_consents c
               JOIN users u ON u.id = c.recorded_by_user_id
              WHERE c.referral_id = :referral_id
              ORDER BY c.consented_at ASC, c.id ASC'
        );
        $statement->execute(['referral_id' => $referralId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        $history = [];

        foreach ($rows as $row) {
            $name = $row['recorded_by_name'] ?? null;

            $history[] = [
                'consent' => ProcedureConsent::fromRow($row),
                'recordedBy' => is_scalar($name) ? (string) $name : 'Unknown user',
            ];
        }

        return $history;
    }

    public function withdraw(int $consentId, Actor $actor, string $reason, string $occurredAtUtc): void
    {
        $update = $this->pdo->prepare(
            'UPDATE procedure_consents
                SET withdrawn_at = :withdrawn_at,
                    withdrawn_by_user_id = :withdrawn_by,
                    withdrawal_reason = :reason
              WHERE id = :id AND withdrawn_at IS NULL'
        );
        $update->execute([
            'withdrawn_at' => $occurredAtUtc,
            'withdrawn_by' => $actor->userId,
            'reason' => $reason,
            'id' => $consentId,
        ]);

        if ($update->rowCount() !== 1) {
            throw new ConsentNotPermittedException(null, 'This agreement was already withdrawn.');
        }
    }
}
