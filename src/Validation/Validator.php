<?php

declare(strict_types=1);

namespace App\Validation;

use DateTimeImmutable;

/**
 * Small allowlist validator. Rules are declared per field, never derived from
 * the request, so unknown input can never become a persisted column.
 */
final class Validator
{
    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules field => "required|max:120|in:a,b"
     *
     * @return array<string, string> field => first error message
     */
    public function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleSet) {
            $raw = $data[$field] ?? null;
            $value = is_string($raw) ? trim($raw) : (is_scalar($raw) ? (string) $raw : '');

            foreach (explode('|', $ruleSet) as $rule) {
                $parts = explode(':', $rule, 2);
                $name = $parts[0];
                $parameter = $parts[1] ?? null;

                $error = $this->apply($name, $parameter, $value, $field);
                if ($error !== null) {
                    $errors[$field] = $error;
                    break;
                }
            }
        }

        return $errors;
    }

    private function apply(string $rule, ?string $parameter, string $value, string $field): ?string
    {
        $label = $this->label($field);

        return match ($rule) {
            'required' => $value === '' ? $label . ' is required.' : null,
            'max' => $value !== '' && mb_strlen($value) > (int) $parameter
                ? $label . ' must be at most ' . (int) $parameter . ' characters.'
                : null,
            'min' => $value !== '' && mb_strlen($value) < (int) $parameter
                ? $label . ' must be at least ' . (int) $parameter . ' characters.'
                : null,
            'in' => $value !== '' && !in_array($value, explode(',', (string) $parameter), true)
                ? $label . ' is not an accepted value.'
                : null,
            'datetime' => $value !== '' && !$this->isLocalDateTime($value)
                ? $label . ' must be a valid date and time.'
                : null,
            'phone' => $value !== '' && preg_match('/^\+?[0-9][0-9 ()\-]{6,19}$/', $value) !== 1
                ? $label . ' does not look like a phone number.'
                : null,
            'email' => $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false
                ? $label . ' is not a valid email address.'
                : null,
            default => null,
        };
    }

    private function isLocalDateTime(string $value): bool
    {
        $format = 'Y-m-d\TH:i';
        $parsed = DateTimeImmutable::createFromFormat('!' . $format, $value);

        return $parsed !== false && $parsed->format($format) === $value;
    }

    private function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }
}
