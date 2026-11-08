<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * Hashing for idempotency records.
 *
 * The fingerprint is taken over a canonicalised payload: keys sorted recursively,
 * scalars cast to strings. Two submissions that differ only in key order or in a
 * string "7" versus integer 7 are the same request, and must not be reported as a
 * conflict.
 */
final class Idempotency
{
    /** Keys the client may not use to claim a request: they are server-owned. */
    private const IGNORED = ['idempotency_key', 'csrf_token', 'submit'];

    public static function hashKey(string $key): string
    {
        $trimmed = trim($key);

        if (strlen($trimmed) < 8 || strlen($trimmed) > 200) {
            throw new InvalidArgumentException('An idempotency key must be 8 to 200 characters.');
        }

        return hash('sha256', $trimmed);
    }

    /** @param array<string, mixed> $payload */
    public static function fingerprint(array $payload): string
    {
        foreach (self::IGNORED as $ignored) {
            unset($payload[$ignored]);
        }

        return hash('sha256', json_encode(self::canonicalise($payload), JSON_THROW_ON_ERROR));
    }

    private static function canonicalise(mixed $value): mixed
    {
        if (!is_array($value)) {
            return is_scalar($value) || $value === null ? (string) $value : '';
        }

        $isList = array_is_list($value);
        $normalised = [];

        foreach ($value as $key => $item) {
            $normalised[$key] = self::canonicalise($item);
        }

        if ($isList) {
            return $normalised;
        }

        ksort($normalised);

        return $normalised;
    }
}
