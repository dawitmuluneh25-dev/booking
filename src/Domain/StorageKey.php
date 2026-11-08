<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * The opaque name a stored file has on disk.
 *
 * A value object rather than a string because the key is the one input that
 * decides a filesystem path: `../../public/index.php` must be unrepresentable,
 * not merely checked at each call site (DOC-04). `fromString` accepts exactly
 * 32 lowercase hex characters, which is what `generate` produces and what the
 * database column stores.
 */
final readonly class StorageKey
{
    private const PATTERN = '/\A[a-f0-9]{32}\z/';

    private function __construct(public string $value)
    {
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }

    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            // The message deliberately does not echo the rejected value: it may
            // be an attempted traversal and it ends up in a log.
            throw new InvalidArgumentException('A storage key must be 32 lowercase hexadecimal characters.');
        }

        return new self($value);
    }

    /** Two-character shard so a directory never holds an unbounded number of files. */
    public function shard(): string
    {
        return substr($this->value, 0, 2);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
