<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\ResourceBlock;
use App\Domain\ResourceBlockRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Blocks, read as intervals and offered as quantum keys.
 *
 * A closure almost never begins on a quarter hour, but the calculator works in
 * fifteen-minute steps. The expansion below rounds outwards, so a block from
 * 10:05 to 10:50 closes 10:00 through 11:00 as far as the picker is concerned —
 * offering 10:00 would let somebody book across the start of the closure. The
 * authoritative check for an actual booking is interval overlap, not this grid,
 * which is why `PdoAppointmentRepository` compares raw timestamps instead of
 * trusting the rounded set.
 */
final class PdoResourceBlockRepository implements ResourceBlockRepository
{
    private const QUANTUM_MINUTES = 15;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, string> */
    public function blockedQuanta(int $resourceId, string $fromUtc, string $toUtc): array
    {
        $quanta = [];

        foreach ($this->overlapping($resourceId, $fromUtc, $toUtc) as $block) {
            $cursor = $this->floorToQuantum($block->startsAtUtc);
            $end = $this->ceilToQuantum($block->endsAtUtc);

            while ($cursor < $end) {
                $quanta[$cursor] = $block->reason;
                $next = $this->shift($cursor, self::QUANTUM_MINUTES);

                if ($next === null || $next <= $cursor) {
                    break;
                }

                $cursor = $next;
            }
        }

        return $quanta;
    }

    /** @return list<ResourceBlock> */
    public function upcoming(int $resourceId, string $fromUtc, int $limit = 20): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, resource_id, starts_at, ends_at, reason, created_by_user_id, created_at
               FROM resource_blocks
              WHERE resource_id = :resource_id AND ends_at > :from_utc
              ORDER BY starts_at ASC
              LIMIT :limit'
        );
        $statement->bindValue('resource_id', $resourceId, PDO::PARAM_INT);
        $statement->bindValue('from_utc', $fromUtc);
        $statement->bindValue('limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $statement->execute();

        $blocks = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $blocks[] = ResourceBlock::fromRow($row);
            }
        }

        return $blocks;
    }

    public function find(int $id): ?ResourceBlock
    {
        $statement = $this->pdo->prepare(
            'SELECT id, resource_id, starts_at, ends_at, reason, created_by_user_id, created_at
               FROM resource_blocks WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return ResourceBlock::fromRow($row);
    }

    public function create(ResourceBlock $block): ResourceBlock
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO resource_blocks
                (resource_id, starts_at, ends_at, reason, created_by_user_id, created_at)
             VALUES (:resource_id, :starts_at, :ends_at, :reason, :created_by, :created_at)'
        );
        $statement->execute([
            'resource_id' => $block->resourceId,
            'starts_at' => $block->startsAtUtc,
            'ends_at' => $block->endsAtUtc,
            'reason' => $block->reason,
            'created_by' => $block->createdByUserId,
            'created_at' => $block->createdAtUtc,
        ]);

        return new ResourceBlock(
            id: (int) $this->pdo->lastInsertId(),
            resourceId: $block->resourceId,
            startsAtUtc: $block->startsAtUtc,
            endsAtUtc: $block->endsAtUtc,
            reason: $block->reason,
            createdByUserId: $block->createdByUserId,
            createdAtUtc: $block->createdAtUtc,
        );
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM resource_blocks WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /** @return list<ResourceBlock> */
    private function overlapping(int $resourceId, string $fromUtc, string $toUtc): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, resource_id, starts_at, ends_at, reason, created_by_user_id, created_at
               FROM resource_blocks
              WHERE resource_id = :resource_id AND starts_at < :to_utc AND ends_at > :from_utc
              ORDER BY starts_at ASC'
        );
        $statement->execute(['resource_id' => $resourceId, 'to_utc' => $toUtc, 'from_utc' => $fromUtc]);

        $blocks = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $blocks[] = ResourceBlock::fromRow($row);
            }
        }

        return $blocks;
    }

    private function floorToQuantum(string $utc): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

        if ($parsed === false) {
            return $utc;
        }

        $minute = (int) $parsed->format('i');
        $remainder = $minute % self::QUANTUM_MINUTES;

        if ($remainder === 0) {
            return $parsed->format('Y-m-d H:i:s');
        }

        $floored = $parsed->modify('-' . $remainder . ' minutes');

        return $floored === false ? $utc : $floored->format('Y-m-d H:i:s');
    }

    private function ceilToQuantum(string $utc): string
    {
        $floored = $this->floorToQuantum($utc);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $floored, new DateTimeZone('UTC'));

        if ($parsed === false) {
            return $utc;
        }

        $shifted = $parsed->modify('+' . self::QUANTUM_MINUTES . ' minutes');

        return $floored === $utc || $shifted === false ? $floored : $shifted->format('Y-m-d H:i:s');
    }

    private function shift(string $utc, int $minutes): ?string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

        if ($parsed === false) {
            return null;
        }

        $shifted = $parsed->modify('+' . $minutes . ' minutes');

        return $shifted === false ? null : $shifted->format('Y-m-d H:i:s');
    }
}
