<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\PatientRelationshipRepository;
use PDO;

/**
 * MySQL implementation of the guardian relationship boundary.
 *
 * Both queries filter on `ended_at IS NULL` and `authority = 'act'` in SQL rather
 * than in PHP: a relationship that ended yesterday must not grant anything today,
 * and leaving that filter to the caller is how it gets forgotten.
 */
final class PdoPatientRelationshipRepository implements PatientRelationshipRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function patientsActedForBy(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT patient_user_id FROM patient_relationships
              WHERE related_user_id = :user_id
                AND authority = 'act'
                AND ended_at IS NULL"
        );
        $statement->execute(['user_id' => $userId]);

        $ids = [];

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }

    public function actsFor(int $userId, int $patientUserId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM patient_relationships
              WHERE related_user_id = :user_id
                AND patient_user_id = :patient_id
                AND authority = 'act'
                AND ended_at IS NULL"
        );
        $statement->execute(['user_id' => $userId, 'patient_id' => $patientUserId]);

        return (int) $statement->fetchColumn() === 1;
    }
}
