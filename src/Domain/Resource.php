<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A bookable thing: a suite, a room, or (later) a clinician or a piece of
 * equipment.
 *
 * `code` is the stable identifier an operator types and a report groups by;
 * `id` is what foreign keys use. Keeping both means renaming "IR Suite 1" does
 * not break a report that was written against the code.
 */
final readonly class Resource
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public string $kind,
        public ?string $modality,
        public bool $isActive,
    ) {
    }

    public function label(): string
    {
        return $this->modality === null || $this->modality === ''
            ? $this->name
            : $this->name . ' (' . $this->modality . ')';
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            code: is_scalar($row['code'] ?? null) ? (string) $row['code'] : '',
            name: is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
            kind: is_scalar($row['kind'] ?? null) ? (string) $row['kind'] : 'room',
            modality: is_scalar($row['modality'] ?? null) && (string) $row['modality'] !== ''
                ? (string) $row['modality']
                : null,
            isActive: is_numeric($row['is_active'] ?? null) ? (int) $row['is_active'] === 1 : true,
        );
    }
}
