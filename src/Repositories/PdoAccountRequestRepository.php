<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\AccountRequest;
use App\Domain\AccountRequestRepository;
use App\Domain\AccountRequestStatus;
use PDO;

/**
 * MySQL implementation of the account-request queue.
 *
 * The two decision writes use `UPDATE ... WHERE status = 'pending'` and report the
 * affected row count, so "already decided" is decided by the database rather than by
 * a read a moment earlier. The schema's CHECK constraints are the second gate: an
 * approved row without an account, or a decided row without a name, cannot be stored
 * even by a writer that skipped this class.
 */
final class PdoAccountRequestRepository implements AccountRequestRepository
{
    private const COLUMNS = 'id, reference, full_name, email, practice_name, practice_city,
        registration_no, note, status, created_at, decided_at, decided_by, decision_reason,
        created_user_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(AccountRequest $request, string $createdAtUtc): AccountRequest
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO account_requests
                (reference, full_name, email, practice_name, practice_city, registration_no,
                 note, status, created_at)
             VALUES
                (:reference, :full_name, :email, :practice_name, :practice_city, :registration_no,
                 :note, :status, :created_at)'
        );

        $statement->execute([
            'reference' => $request->reference,
            'full_name' => $request->fullName,
            'email' => $request->email,
            'practice_name' => $request->practiceName,
            'practice_city' => $request->practiceCity,
            'registration_no' => $request->registrationNo,
            'note' => $request->note,
            'status' => AccountRequestStatus::Pending->value,
            'created_at' => $createdAtUtc,
        ]);

        return $request->withIdentity((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?AccountRequest
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM account_requests WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function pending(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM account_requests
             WHERE status = :status
             ORDER BY created_at ASC, id ASC
             LIMIT :limit'
        );
        $statement->bindValue('status', AccountRequestStatus::Pending->value);
        $statement->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return $this->all($statement);
    }

    public function recentlyDecided(int $limit = 20): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM account_requests
             WHERE status <> :status
             ORDER BY decided_at DESC, id DESC
             LIMIT :limit'
        );
        $statement->bindValue('status', AccountRequestStatus::Pending->value);
        $statement->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return $this->all($statement);
    }

    public function findRecentDuplicate(string $email, string $practiceName, string $sinceUtc): ?AccountRequest
    {
        // LOWER() on both sides because the two engines this runs on collate
        // differently; a case-different practice name is the same practice.
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM account_requests
             WHERE email = :email
               AND LOWER(practice_name) = LOWER(:practice_name)
               AND created_at >= :since
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute([
            'email' => $email,
            'practice_name' => $practiceName,
            'since' => $sinceUtc,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function referenceExists(string $reference): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM account_requests WHERE reference = :reference LIMIT 1');
        $statement->execute(['reference' => $reference]);

        return $statement->fetchColumn() !== false;
    }

    public function markApproved(
        int $id,
        int $decidedByUserId,
        int $createdUserId,
        string $decidedAtUtc,
        ?string $reason,
    ): bool {
        $statement = $this->pdo->prepare(
            "UPDATE account_requests
             SET status = 'approved', decided_at = :decided_at, decided_by = :decided_by,
                 decision_reason = :reason, created_user_id = :created_user_id
             WHERE id = :id AND status = 'pending'"
        );

        $statement->execute([
            'decided_at' => $decidedAtUtc,
            'decided_by' => $decidedByUserId,
            'reason' => $reason,
            'created_user_id' => $createdUserId,
            'id' => $id,
        ]);

        return $statement->rowCount() === 1;
    }

    public function markRejected(int $id, int $decidedByUserId, string $reason, string $decidedAtUtc): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE account_requests
             SET status = 'rejected', decided_at = :decided_at, decided_by = :decided_by,
                 decision_reason = :reason
             WHERE id = :id AND status = 'pending'"
        );

        $statement->execute([
            'decided_at' => $decidedAtUtc,
            'decided_by' => $decidedByUserId,
            'reason' => $reason,
            'id' => $id,
        ]);

        return $statement->rowCount() === 1;
    }

    public function expirePendingOlderThan(string $cutoffUtc): int
    {
        $statement = $this->pdo->prepare(
            "UPDATE account_requests
             SET status = 'expired'
             WHERE status = 'pending' AND created_at < :cutoff"
        );

        $statement->execute(['cutoff' => $cutoffUtc]);

        return $statement->rowCount();
    }

    public function countPending(): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM account_requests WHERE status = 'pending'"
        );
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * @param \PDOStatement $statement executed, unread
     *
     * @return list<AccountRequest>
     */
    private function all(\PDOStatement $statement): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $requests = [];

        foreach ($rows as $row) {
            $requests[] = $this->hydrate($row);
        }

        return $requests;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): AccountRequest
    {
        $status = AccountRequestStatus::tryFrom($this->text($row, 'status'))
            ?? AccountRequestStatus::Pending;

        return new AccountRequest(
            id: $this->integer($row, 'id'),
            reference: $this->text($row, 'reference'),
            fullName: $this->text($row, 'full_name'),
            email: $this->text($row, 'email'),
            practiceName: $this->text($row, 'practice_name'),
            practiceCity: $this->text($row, 'practice_city'),
            registrationNo: $this->nullable($row, 'registration_no'),
            note: $this->nullable($row, 'note'),
            status: $status,
            createdAtUtc: $this->text($row, 'created_at'),
            decidedAtUtc: $this->nullable($row, 'decided_at'),
            decidedByUserId: $this->nullableInt($row, 'decided_by'),
            decisionReason: $this->nullable($row, 'decision_reason'),
            createdUserId: $this->nullableInt($row, 'created_user_id'),
        );
    }

    /** @param array<string, mixed> $row */
    private function text(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_scalar($value) ? (int) $value : 0;
    }

    /** @param array<string, mixed> $row */
    private function nullable(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /** @param array<string, mixed> $row */
    private function nullableInt(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? (int) $value : null;
    }
}
