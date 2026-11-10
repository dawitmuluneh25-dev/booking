<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\ResourceBlock;
use App\Domain\ResourceBlockRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The same interval semantics as the PDO version, without the database.
 *
 * The expansion is deliberately written out again rather than shared: a double
 * that imports the production rounding would agree with the production bug.
 */
final class InMemoryResourceBlockRepository implements ResourceBlockRepository
{
    /** @var list<ResourceBlock> */
    private array $blocks = [];

    private int $nextId = 1;

    public function seed(ResourceBlock $block): ResourceBlock
    {
        $stored = new ResourceBlock(
            id: $this->nextId++,
            resourceId: $block->resourceId,
            startsAtUtc: $block->startsAtUtc,
            endsAtUtc: $block->endsAtUtc,
            reason: $block->reason,
            createdByUserId: $block->createdByUserId,
            createdAtUtc: $block->createdAtUtc,
        );

        $this->blocks[] = $stored;

        return $stored;
    }

    /** @return array<string, string> */
    public function blockedQuanta(int $resourceId, string $fromUtc, string $toUtc): array
    {
        $quanta = [];

        foreach ($this->blocks as $block) {
            if ($block->resourceId !== $resourceId || !$block->overlaps($fromUtc, $toUtc)) {
                continue;
            }

            $cursor = $this->floor($block->startsAtUtc);
            $end = $this->ceil($block->endsAtUtc);

            while ($cursor < $end) {
                $quanta[$cursor] = $block->reason;
                $cursor = $this->shift($cursor);
            }
        }

        return $quanta;
    }

    /** @return list<ResourceBlock> */
    public function upcoming(int $resourceId, string $fromUtc, int $limit = 20): array
    {
        $blocks = [];

        foreach ($this->blocks as $block) {
            if ($block->resourceId === $resourceId && $block->endsAtUtc > $fromUtc) {
                $blocks[] = $block;
            }
        }

        usort($blocks, static fn (ResourceBlock $a, ResourceBlock $b): int => strcmp($a->startsAtUtc, $b->startsAtUtc));

        return array_slice($blocks, 0, max(1, $limit));
    }

    public function find(int $id): ?ResourceBlock
    {
        foreach ($this->blocks as $block) {
            if ($block->id === $id) {
                return $block;
            }
        }

        return null;
    }

    public function create(ResourceBlock $block): ResourceBlock
    {
        return $this->seed($block);
    }

    public function delete(int $id): void
    {
        $this->blocks = array_values(array_filter(
            $this->blocks,
            static fn (ResourceBlock $block): bool => $block->id !== $id,
        ));
    }

    private function floor(string $utc): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

        if ($parsed === false) {
            return $utc;
        }

        $remainder = (int) $parsed->format('i') % 15;

        if ($remainder === 0) {
            return $parsed->format('Y-m-d H:i:s');
        }

        $floored = $parsed->modify('-' . $remainder . ' minutes');

        return $floored === false ? $utc : $floored->format('Y-m-d H:i:s');
    }

    private function ceil(string $utc): string
    {
        $floored = $this->floor($utc);

        if ($floored === $utc) {
            return $floored;
        }

        return $this->shift($floored);
    }

    private function shift(string $utc): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

        if ($parsed === false) {
            return $utc;
        }

        $shifted = $parsed->modify('+15 minutes');

        return $shifted === false ? $utc : $shifted->format('Y-m-d H:i:s');
    }
}
