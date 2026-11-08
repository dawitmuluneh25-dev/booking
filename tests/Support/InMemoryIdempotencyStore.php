<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Actor;
use App\Domain\Idempotency;
use App\Domain\IdempotencyClaim;
use App\Domain\IdempotencyStore;

/**
 * The same three answers as the MySQL store, without the database.
 *
 * Keeping the in-memory version faithful matters: a command test that passes
 * here but fails against MySQL teaches nothing. So this double also refuses a
 * mismatched fingerprint and a still-running claim, rather than only handling the
 * happy replay.
 */
final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, array{fingerprint: string, status: string, reference: string|null}> */
    private array $records = [];

    public function claim(
        string $scope,
        string $key,
        string $fingerprint,
        Actor $actor,
        string $nowUtc,
    ): IdempotencyClaim {
        $id = $scope . '|' . Idempotency::hashKey($key);

        if (!isset($this->records[$id])) {
            $this->records[$id] = [
                'fingerprint' => $fingerprint,
                'status' => 'in_progress',
                'reference' => null,
            ];

            return IdempotencyClaim::fresh();
        }

        $record = $this->records[$id];

        if ($record['fingerprint'] !== $fingerprint) {
            return IdempotencyClaim::conflict('The same key was used for a different request.');
        }

        if ($record['status'] === 'in_progress') {
            return IdempotencyClaim::conflict('The first attempt is still running.');
        }

        return $record['reference'] === null
            ? IdempotencyClaim::conflict('The earlier request finished without a result.')
            : IdempotencyClaim::replay($record['reference']);
    }

    public function complete(string $scope, string $key, string $reference, string $nowUtc): void
    {
        $id = $scope . '|' . Idempotency::hashKey($key);

        if (!isset($this->records[$id])) {
            return;
        }

        $this->records[$id]['status'] = 'completed';
        $this->records[$id]['reference'] = $reference;
    }
}
