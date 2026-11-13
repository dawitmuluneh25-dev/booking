<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A laboratory a specimen can be sent to (§19.1).
 *
 * The serial is the whole identity. Both the code and the label are derived from
 * it, so a mismatched pair — code saying 2 while the label says 1 — is not a state
 * the model can represent. That matters more than it looks: a specimen record
 * stores the reference, and a laboratory that could be renamed independently of its
 * serial would make historical records read as if they pointed somewhere else.
 */
final class LaboratoryDestination
{
    public function __construct(
        public readonly int $id,
        public readonly int $serialNo,
        public readonly bool $isActive,
    ) {
    }

    /** The stable identifier a specimen record stores or cites in an export. */
    public function code(): string
    {
        return 'PATHOLOGY-LAB-' . $this->serialNo;
    }

    public function label(): string
    {
        return 'Pathology Lab ' . $this->serialNo;
    }

    public function withActive(bool $active): self
    {
        return new self($this->id, $this->serialNo, $active);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            serialNo: is_numeric($row['serial_no'] ?? null) ? (int) $row['serial_no'] : 0,
            isActive: is_numeric($row['is_active'] ?? null) ? (int) $row['is_active'] === 1 : false,
        );
    }
}
