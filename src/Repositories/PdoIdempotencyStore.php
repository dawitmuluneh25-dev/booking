<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Actor;
use App\Domain\Idempotency;
use App\Domain\IdempotencyClaim;
use App\Domain\IdempotencyStore;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOException;

/**
 * MySQL idempotency store.
 *
 * The unique key on (scope, key_hash) is the lock: two simultaneous claims cannot
 * both win, and the loser reads the winner's row instead of running the work. A
 * separate SELECT-then-INSERT would leave a window where both callers see nothing.
 */
final class PdoIdempotencyStore implements IdempotencyStore
{
    /** A claim older than this is presumed dead rather than still running. */
    private const LIFETIME_HOURS = 24;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function claim(
        string $scope,
        string $key,
        string $fingerprint,
        Actor $actor,
        string $nowUtc,
    ): IdempotencyClaim {
        $hash = Idempotency::hashKey($key);

        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO idempotency_keys
                    (scope, key_hash, request_fingerprint, actor_user_id, status, created_at, expires_at)
                 VALUES (:scope, :key_hash, :fingerprint, :actor, \'in_progress\', :created_at, :expires_at)'
            );
            $insert->execute([
                'scope' => $scope,
                'key_hash' => $hash,
                'fingerprint' => $fingerprint,
                'actor' => $actor->userId,
                'created_at' => $nowUtc,
                'expires_at' => $this->expiry($nowUtc),
            ]);

            return IdempotencyClaim::fresh();
        } catch (PDOException $exception) {
            // 23000 is the duplicate-key signal; anything else is a real failure.
            if (($exception->errorInfo[0] ?? '') !== '23000') {
                throw $exception;
            }
        }

        $existing = $this->read($scope, $hash);

        if ($existing === null) {
            // The conflicting row vanished between the insert and the read, which
            // means something is deleting records underneath a live request.
            return IdempotencyClaim::conflict('The earlier request could not be read.');
        }

        if ($existing['fingerprint'] !== $fingerprint) {
            return IdempotencyClaim::conflict('The same key was used for a different request.');
        }

        if ($existing['status'] === 'in_progress') {
            return IdempotencyClaim::conflict('The first attempt is still running.');
        }

        if ($existing['reference'] === null || $existing['reference'] === '') {
            return IdempotencyClaim::conflict('The earlier request finished without a result.');
        }

        return IdempotencyClaim::replay($existing['reference']);
    }

    public function complete(string $scope, string $key, string $reference, string $nowUtc): void
    {
        $update = $this->pdo->prepare(
            'UPDATE idempotency_keys
                SET status = \'completed\', response_reference = :reference, completed_at = :completed_at
              WHERE scope = :scope AND key_hash = :key_hash AND status = \'in_progress\''
        );
        $update->execute([
            'reference' => $reference,
            'completed_at' => $nowUtc,
            'scope' => $scope,
            'key_hash' => Idempotency::hashKey($key),
        ]);
    }

    /** @return array{fingerprint: string, status: string, reference: string|null}|null */
    private function read(string $scope, string $hash): ?array
    {
        $select = $this->pdo->prepare(
            'SELECT request_fingerprint, status, response_reference
               FROM idempotency_keys
              WHERE scope = :scope AND key_hash = :key_hash'
        );
        $select->execute(['scope' => $scope, 'key_hash' => $hash]);

        /** @var array<string, mixed>|false $row */
        $row = $select->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'fingerprint' => is_scalar($row['request_fingerprint'] ?? null)
                ? (string) $row['request_fingerprint']
                : '',
            'status' => is_scalar($row['status'] ?? null) ? (string) $row['status'] : '',
            'reference' => is_scalar($row['response_reference'] ?? null)
                ? (string) $row['response_reference']
                : null,
        ];
    }

    private function expiry(string $nowUtc): string
    {
        $now = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $nowUtc, new DateTimeZone('UTC'));

        if ($now === false) {
            throw new InvalidArgumentException('The supplied timestamp is not in UTC format.');
        }

        return $now->add(new DateInterval('PT' . self::LIFETIME_HOURS . 'H'))
            ->format('Y-m-d H:i:s');
    }
}
