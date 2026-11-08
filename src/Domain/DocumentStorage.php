<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * Private byte storage.
 *
 * The interface takes a `StorageKey` and never a path, so no caller can hand a
 * web-controlled string to the filesystem. Implementations must keep the root
 * outside the public document root (SEC 14.2).
 */
interface DocumentStorage
{
    /**
     * Copy an uploaded temporary file into private storage.
     *
     * Writing a new key never overwrites an existing object; a collision is an
     * error rather than a silent replacement (SEC 14.2).
     *
     * @throws RuntimeException when the file cannot be stored
     */
    public function write(StorageKey $key, string $sourcePath): void;

    /**
     * @throws RuntimeException when the object is missing or unreadable
     */
    public function read(StorageKey $key): string;

    /** False when there was nothing to remove; missing files are not an error. */
    public function delete(StorageKey $key): bool;

    public function exists(StorageKey $key): bool;
}
