<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One parsed JSON-lines log entry.
 */
final readonly class LogRecord
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $timestamp,
        public string $level,
        public string $message,
        public array $context,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): ?self
    {
        $level = $row['level'] ?? null;
        $message = $row['message'] ?? null;

        if (!is_string($level) || !is_string($message)) {
            return null;
        }

        $timestamp = $row['ts'] ?? '';
        $context = $row['context'] ?? [];

        return new self(
            timestamp: is_string($timestamp) ? $timestamp : '',
            level: $level,
            message: $message,
            context: is_array($context) ? $context : [],
        );
    }

    public function contextLine(): string
    {
        if ($this->context === []) {
            return '';
        }

        $parts = [];
        foreach ($this->context as $key => $value) {
            $parts[] = $key . '=' . (is_scalar($value) ? (string) $value : gettype($value));
        }

        return implode(' ', $parts);
    }
}
