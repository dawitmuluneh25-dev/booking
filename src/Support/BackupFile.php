<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Metadata for one logical backup on disk.
 */
final readonly class BackupFile
{
    public function __construct(
        public string $path,
        public string $name,
        public int $bytes,
        public int $modifiedAt,
        public int $tables = 0,
        public int $rows = 0,
        public bool $encrypted = false,
    ) {
    }

    public function ageSeconds(?int $now = null): int
    {
        return max(0, ($now ?? time()) - $this->modifiedAt);
    }

    public function humanSize(): string
    {
        if ($this->bytes < 1024) {
            return $this->bytes . ' B';
        }

        if ($this->bytes < 1024 * 1024) {
            return round($this->bytes / 1024, 1) . ' KiB';
        }

        return round($this->bytes / (1024 * 1024), 1) . ' MiB';
    }
}
