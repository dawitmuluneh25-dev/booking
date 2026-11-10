<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A service the clinic offers, as configured rather than as coded.
 *
 * The label is the only human-facing name; the code is an identifier and is
 * never shown to a patient or a referrer. `requiredFields` is empty until
 * clinical governance approves a field set for this procedure, and the database
 * refuses an approved field set that names no approver.
 */
final class Procedure
{
    /**
     * @param array<string, string>|null $requiredFields field => validation rule
     */
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly string $category,
        public readonly int $defaultDurationMinutes,
        public readonly ?array $requiredFields,
        public readonly int $fieldSetVersion,
        public readonly ?string $approvedBy,
        public readonly string $approvedAtUtc,
        public readonly bool $active,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $fields = null;

        if (isset($row['required_fields']) && is_string($row['required_fields']) && $row['required_fields'] !== '') {
            $decoded = json_decode($row['required_fields'], true);

            if (is_array($decoded)) {
                $fields = [];
                foreach ($decoded as $key => $rule) {
                    if (is_string($key) && is_string($rule)) {
                        $fields[$key] = $rule;
                    }
                }
            }
        }

        return new self(
            code: self::text($row['code'] ?? null),
            label: self::text($row['label'] ?? null),
            category: self::text($row['category'] ?? null),
            defaultDurationMinutes: is_numeric($row['default_duration_minutes'] ?? null)
                ? (int) $row['default_duration_minutes']
                : 0,
            requiredFields: $fields,
            fieldSetVersion: is_numeric($row['field_set_version'] ?? null)
                ? (int) $row['field_set_version']
                : 1,
            approvedBy: isset($row['approved_by']) ? self::text($row['approved_by']) : null,
            approvedAtUtc: self::text($row['approved_at'] ?? null),
            active: (bool) ($row['active'] ?? false),
        );
    }

    /** Whether this procedure may be offered on the elective intake form. */
    public function isElective(): bool
    {
        return $this->category === 'elective' && $this->active;
    }

    /**
     * The extra fields the intake form must render for this procedure.
     *
     * Empty, not null, so a form can loop without asking a second question.
     *
     * @return array<string, string>
     */
    public function extraFields(): array
    {
        return $this->requiredFields ?? [];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
