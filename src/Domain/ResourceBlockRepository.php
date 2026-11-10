<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Closure windows for bookable resources.
 *
 * Two readers, one table: the schedule asks "which quanta must not be offered"
 * and the admin screen asks "what is closed and why". Both questions are answered
 * here so the answer cannot differ between the calendar and the booking form.
 */
interface ResourceBlockRepository
{
    /**
     * Blocked quanta for one resource in a UTC range.
     *
     * Keys are UTC `Y-m-d H:i:s` at fifteen-minute steps, matching
     * `ResourceSchedule::quantumStarts()`, so a block and a booking can be
     * compared without either side re-deriving the grid.
     *
     * @return array<string, string> quantum start => reason
     */
    public function blockedQuanta(int $resourceId, string $fromUtc, string $toUtc): array;

    /**
     * Closed windows for one resource from a UTC instant onwards, soonest first.
     *
     * @return list<ResourceBlock>
     */
    public function upcoming(int $resourceId, string $fromUtc, int $limit = 20): array;

    public function find(int $id): ?ResourceBlock;

    public function create(ResourceBlock $block): ResourceBlock;

    /** Removing a block is allowed; removing an appointment is not. */
    public function delete(int $id): void;
}
