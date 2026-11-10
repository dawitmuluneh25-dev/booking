<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A window in which a resource must not be offered.
 *
 * The reason is part of the object, not an audit afterthought: reception asks
 * "why is Thursday closed" and the answer has to be on the same row as the
 * closure, otherwise the block is indistinguishable from a booking.
 */
final readonly class ResourceBlock
{
    public function __construct(
        public int $id,
        public int $resourceId,
        public string $startsAtUtc,
        public string $endsAtUtc,
        public string $reason,
        public int $createdByUserId,
        public string $createdAtUtc,
    ) {
    }

    /** Whether any part of the block falls inside a UTC range. */
    public function overlaps(string $fromUtc, string $toUtc): bool
    {
        return $this->startsAtUtc < $toUtc && $this->endsAtUtc > $fromUtc;
    }

    /** The window as a receptionist reads it. */
    public function describe(DateTimeZone $zone): string
    {
        $starts = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $this->startsAtUtc, new DateTimeZone('UTC'));
        $ends = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $this->endsAtUtc, new DateTimeZone('UTC'));

        if ($starts === false || $ends === false) {
            return $this->startsAtUtc . ' – ' . $this->endsAtUtc;
        }

        return $starts->setTimezone($zone)->format('D j M Y, H:i')
            . ' – '
            . $ends->setTimezone($zone)->format('D j M Y, H:i');
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            resourceId: is_numeric($row['resource_id'] ?? null) ? (int) $row['resource_id'] : 0,
            startsAtUtc: is_scalar($row['starts_at'] ?? null) ? (string) $row['starts_at'] : '',
            endsAtUtc: is_scalar($row['ends_at'] ?? null) ? (string) $row['ends_at'] : '',
            reason: is_scalar($row['reason'] ?? null) ? (string) $row['reason'] : '',
            createdByUserId: is_numeric($row['created_by_user_id'] ?? null) ? (int) $row['created_by_user_id'] : 0,
            createdAtUtc: is_scalar($row['created_at'] ?? null) ? (string) $row['created_at'] : '',
        );
    }
}
