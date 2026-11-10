<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\PhysicianProfile;
use App\Domain\PhysicianQueueEntry;
use App\Domain\PhysicianProfileRepository;
use App\Domain\PhysicianReviewException;
use App\Domain\PhysicianStatus;
use App\Domain\Transaction;
use PDO;

/**
 * MySQL implementation of the physician profile boundary.
 *
 * A review is a guarded update: it applies only while the stored status is still
 * the one the reviewer read, so two administrators working the queue cannot both
 * decide the same profile and have the second decision silently win.
 */
final class PdoPhysicianProfileRepository implements PhysicianProfileRepository
{
    private const COLUMNS = 'id, user_id, practice_number, institution, specialty, status,
        verified_by_user_id, verified_at, review_note, created_at';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Transaction $transaction,
    ) {
    }

    public function findByUserId(int $userId): ?PhysicianProfile
    {
        return $this->findOne('user_id = :value', (string) $userId);
    }

    public function findById(int $id): ?PhysicianProfile
    {
        return $this->findOne('id = :value', (string) $id);
    }

    public function pending(): array
    {
        $statement = $this->pdo->query(
            "SELECT " . self::COLUMNS . " FROM physician_profiles
              WHERE status = 'pending' ORDER BY created_at ASC"
        );

        if ($statement === false) {
            return [];
        }

        $profiles = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $profiles[] = PhysicianProfile::fromRow($row);
        }

        return $profiles;
    }

    /** @return list<PhysicianQueueEntry> */
    public function queued(?PhysicianStatus $status = null): array
    {
        $sql = 'SELECT p.id, p.user_id, p.practice_number, p.institution, p.specialty, p.status,
                       p.verified_by_user_id, p.verified_at, p.review_note, p.created_at,
                       u.name AS account_name, u.email AS account_email
                  FROM physician_profiles p
                  JOIN users u ON u.id = p.user_id';

        if ($status !== null) {
            $sql .= ' WHERE p.status = :status';
        }

        $sql .= ' ORDER BY p.created_at ASC, p.id ASC';

        $statement = $this->pdo->prepare($sql);

        if ($status !== null) {
            $statement->execute(['status' => $status->value]);
        } else {
            $statement->execute();
        }

        $entries = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $entries[] = new PhysicianQueueEntry(
                profile: PhysicianProfile::fromRow($row),
                name: is_string($row['account_name'] ?? null) ? $row['account_name'] : '',
                email: is_string($row['account_email'] ?? null) ? $row['account_email'] : '',
            );
        }

        return $entries;
    }
    public function create(PhysicianProfile $profile, string $createdAtUtc): PhysicianProfile
    {
        return $this->transaction->run(
            function () use ($profile, $createdAtUtc): PhysicianProfile {
                $insert = $this->pdo->prepare(
                    'INSERT INTO physician_profiles
                        (user_id, practice_number, institution, specialty, status,
                         verified_by_user_id, verified_at, review_note, created_at, updated_at)
                     VALUES
                         (:user_id, :practice_number, :institution, :specialty, :status,
                          NULL, NULL, NULL, :created_at, :updated_at)'
                );
                // Distinct names for created_at/updated_at: emulated prepares are
                // off, so one named parameter may appear only once per statement.
                $insert->execute([
                    'user_id' => $profile->userId,
                    'practice_number' => $profile->practiceNumber,
                    'institution' => $profile->institution,
                    'specialty' => $profile->specialty,
                    'status' => $profile->status->value,
                    'created_at' => $createdAtUtc,
                    'updated_at' => $createdAtUtc,
                ]);

                return $profile->withIdentity((int) $this->pdo->lastInsertId());
            },
        );
    }

    public function review(
        PhysicianProfile $profile,
        PhysicianStatus $status,
        int $reviewerUserId,
        ?string $note,
        string $reviewedAtUtc,
    ): PhysicianProfile {
        return $this->transaction->run(
            function () use ($profile, $status, $reviewerUserId, $note, $reviewedAtUtc): PhysicianProfile {
                $update = $this->pdo->prepare(
                    'UPDATE physician_profiles
                        SET status = :status, verified_by_user_id = :reviewer,
                            verified_at = :reviewed_at, review_note = :note, updated_at = :updated_at
                      WHERE id = :id AND status = :expected'
                );
                $update->execute([
                    'status' => $status->value,
                    'reviewer' => $reviewerUserId,
                    'reviewed_at' => $reviewedAtUtc,
                    'note' => $note,
                    'updated_at' => $reviewedAtUtc,
                    'id' => $profile->id,
                    'expected' => $profile->status->value,
                ]);

                if ($update->rowCount() !== 1) {
                    throw new PhysicianReviewException(
                        'Another administrator reviewed this profile first.',
                    );
                }

                return $profile->withStatus($status, $reviewerUserId, $reviewedAtUtc, $note);
            },
        );
    }

    private function findOne(string $condition, string $value): ?PhysicianProfile
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM physician_profiles WHERE ' . $condition . ' LIMIT 1'
        );
        $statement->execute(['value' => $value]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : PhysicianProfile::fromRow($row);
    }
}
