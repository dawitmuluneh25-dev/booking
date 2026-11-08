<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\DocumentStorage;
use App\Domain\StorageKey;
use RuntimeException;

/**
 * Byte storage held in memory.
 *
 * It refuses to overwrite an existing key, like the filesystem adapter, so a test
 * that would clobber a document fails here too.
 */
final class InMemoryDocumentStorage implements DocumentStorage
{
    /** @var array<string, string> */
    private array $objects = [];

    /** @var list<string> */
    private array $deleted = [];

    public function write(StorageKey $key, string $sourcePath): void
    {
        if (isset($this->objects[$key->value])) {
            throw new RuntimeException('The document could not be stored.');
        }

        $bytes = @file_get_contents($sourcePath);

        if ($bytes === false) {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        $this->objects[$key->value] = $bytes;
    }

    public function read(StorageKey $key): string
    {
        if (!isset($this->objects[$key->value])) {
            throw new RuntimeException('The document bytes are not available.');
        }

        return $this->objects[$key->value];
    }

    public function delete(StorageKey $key): bool
    {
        if (!isset($this->objects[$key->value])) {
            return false;
        }

        unset($this->objects[$key->value]);
        $this->deleted[] = $key->value;

        return true;
    }

    public function exists(StorageKey $key): bool
    {
        return isset($this->objects[$key->value]);
    }

    /** @return list<string> */
    public function deletedKeys(): array
    {
        return $this->deleted;
    }

    public function count(): int
    {
        return count($this->objects);
    }

    /** Puts bytes in storage without going through an upload, for read tests. */
    public function seed(StorageKey $key, string $bytes): void
    {
        $this->objects[$key->value] = $bytes;
    }
}
