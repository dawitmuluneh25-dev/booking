<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One medication as it was reported (MED-002).
 *
 * Only the name is required, because that is what a patient reliably knows. Dose and
 * frequency are free text because "5 mg, half a tablet on alternate days" is a real
 * answer and a numeric pair would force it into a shape that loses the qualifier.
 *
 * Nothing here computes anything: the package forbids issuing stop/restart
 * instructions from this list (§6.5, MED-003), so a medication is a statement, not a
 * trigger.
 */
final class Medication
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $dose = null,
        public readonly ?string $frequency = null,
    ) {
    }

    public function describe(): string
    {
        $parts = array_values(array_filter(
            [$this->dose, $this->frequency],
            static fn (?string $part): bool => $part !== null && $part !== '',
        ));

        return $parts === [] ? $this->name : $this->name . ' — ' . implode(', ', $parts);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $dose = $row['dose'] ?? null;
        $frequency = $row['frequency'] ?? null;

        return new self(
            name: is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
            dose: is_scalar($dose) && (string) $dose !== '' ? (string) $dose : null,
            frequency: is_scalar($frequency) && (string) $frequency !== '' ? (string) $frequency : null,
        );
    }
}
