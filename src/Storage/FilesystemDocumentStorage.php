<?php

declare(strict_types=1);

namespace App\Storage;

use App\Domain\DocumentStorage;
use App\Domain\StorageKey;
use RuntimeException;

/**
 * Private document storage on the local filesystem.
 *
 * The root is `var/private-uploads`, which sits outside `public/` and is
 * therefore not reachable by URL at all. `.htaccess` files are written defensively
 * for a misconfigured document root, but they are the second line, not the first
 * (SEC 14.2).
 *
 * Files are stored under their random key with no extension, so even a directory
 * that somehow became web-served would hand back an untyped blob rather than
 * something a browser is invited to execute.
 */
final class FilesystemDocumentStorage implements DocumentStorage
{
    private const DENY_ALL = "Require all denied\nDeny from all\n";

    public function __construct(private readonly string $root)
    {
        $this->ensureDirectory($this->root);
    }

    public function write(StorageKey $key, string $sourcePath): void
    {
        if (!is_file($sourcePath)) {
            throw new RuntimeException('The uploaded file is no longer available.');
        }

        $directory = $this->directoryFor($key);
        $this->ensureDirectory($directory);

        $target = $this->pathFor($key);

        // 'x' is the whole point: it fails if the object already exists, so a
        // storage-key collision can never overwrite an existing document in place
        // (SEC 14.2, Phase 4 criterion 1).
        $out = @fopen($target, 'xb');

        if ($out === false) {
            throw new RuntimeException('The document could not be stored.');
        }

        $in = @fopen($sourcePath, 'rb');

        if ($in === false) {
            fclose($out);
            @unlink($target);

            throw new RuntimeException('The uploaded file could not be read.');
        }

        try {
            if (stream_copy_to_stream($in, $out) === false) {
                throw new RuntimeException('The document could not be written.');
            }
        } catch (\Throwable $exception) {
            fclose($out);
            fclose($in);
            @unlink($target);

            throw $exception;
        }

        fclose($in);
        fclose($out);
    }

    public function read(StorageKey $key): string
    {
        $path = $this->pathFor($key);

        if (!is_file($path)) {
            throw new RuntimeException('The document bytes are not available.');
        }

        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw new RuntimeException('The document bytes could not be read.');
        }

        return $bytes;
    }

    public function delete(StorageKey $key): bool
    {
        $path = $this->pathFor($key);

        if (!is_file($path)) {
            return false;
        }

        if (!@unlink($path)) {
            throw new RuntimeException('The document could not be removed from private storage.');
        }

        return true;
    }

    public function exists(StorageKey $key): bool
    {
        return is_file($this->pathFor($key));
    }

    private function pathFor(StorageKey $key): string
    {
        return $this->directoryFor($key) . DIRECTORY_SEPARATOR . $key->value;
    }

    private function directoryFor(StorageKey $key): string
    {
        return $this->root . DIRECTORY_SEPARATOR . $key->shard();
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Private document storage is not writable: ' . $directory);
        }

        $deny = $directory . DIRECTORY_SEPARATOR . '.htaccess';

        if (!is_file($deny)) {
            @file_put_contents($deny, self::DENY_ALL);
        }
    }
}
