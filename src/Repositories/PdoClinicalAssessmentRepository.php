<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\ClinicalAssessment;
use App\Domain\ClinicalAssessmentRepository;
use PDO;

/**
 * MySQL implementation of the assessment boundary.
 *
 * Insert-only. The only reason this class has no update method is that revising an
 * assessment is a clinical act with a time and an author, and an UPDATE would erase
 * the earlier answer that a decision may have relied on.
 */
final class PdoClinicalAssessmentRepository implements ClinicalAssessmentRepository
{
    private const COLUMNS = 'id, referral_id, anticoagulant_status, anticoagulant_details,
        allergy_status, allergy_details, pregnancy_status, pregnancy_details, relevant_history,
        prior_procedure, examination_context, imaging_modality, imaging_date, imaging_reference,
        recorded_by_user_id, recorded_at, supersedes_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(ClinicalAssessment $assessment): ClinicalAssessment
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO referral_clinical_assessments
                (referral_id, anticoagulant_status, anticoagulant_details, allergy_status,
                 allergy_details, pregnancy_status, pregnancy_details, relevant_history,
                 prior_procedure, examination_context, imaging_modality, imaging_date,
                 imaging_reference, recorded_by_user_id, recorded_at, supersedes_id)
             VALUES
                (:referral_id, :anticoagulant_status, :anticoagulant_details, :allergy_status,
                 :allergy_details, :pregnancy_status, :pregnancy_details, :relevant_history,
                 :prior_procedure, :examination_context, :imaging_modality, :imaging_date,
                 :imaging_reference, :recorded_by, :recorded_at, :supersedes)'
        );

        $insert->execute([
            'referral_id' => $assessment->referralId,
            'anticoagulant_status' => $assessment->anticoagulant?->value,
            'anticoagulant_details' => $assessment->anticoagulantDetails,
            'allergy_status' => $assessment->allergy?->value,
            'allergy_details' => $assessment->allergyDetails,
            'pregnancy_status' => $assessment->pregnancy?->value,
            'pregnancy_details' => $assessment->pregnancyDetails,
            'relevant_history' => $assessment->relevantHistory,
            'prior_procedure' => $assessment->priorProcedure,
            'examination_context' => $assessment->examinationContext,
            'imaging_modality' => $assessment->imagingModality?->value,
            'imaging_date' => $assessment->imagingDate,
            'imaging_reference' => $assessment->imagingReference,
            'recorded_by' => $assessment->recordedByUserId,
            'recorded_at' => $assessment->recordedAtUtc,
            'supersedes' => $assessment->supersedesId,
        ]);

        return $assessment->withIdentity((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?ClinicalAssessment
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM referral_clinical_assessments WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : ClinicalAssessment::fromRow($row);
    }

    public function current(int $referralId): ?ClinicalAssessment
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM referral_clinical_assessments a
              WHERE a.referral_id = :referral_id
                AND NOT EXISTS (
                    SELECT 1 FROM referral_clinical_assessments later
                     WHERE later.supersedes_id = a.id
                )
              ORDER BY a.recorded_at DESC, a.id DESC
              LIMIT 1'
        );
        $statement->execute(['referral_id' => $referralId]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : ClinicalAssessment::fromRow($row);
    }

    public function history(int $referralId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.*, u.name AS recorded_by_name
               FROM referral_clinical_assessments a
               JOIN users u ON u.id = a.recorded_by_user_id
              WHERE a.referral_id = :referral_id
              ORDER BY a.recorded_at ASC, a.id ASC'
        );
        $statement->execute(['referral_id' => $referralId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        $history = [];

        foreach ($rows as $row) {
            $name = $row['recorded_by_name'] ?? null;

            $history[] = [
                'assessment' => ClinicalAssessment::fromRow($row),
                'recordedBy' => is_scalar($name) ? (string) $name : 'Unknown user',
            ];
        }

        return $history;
    }
}
