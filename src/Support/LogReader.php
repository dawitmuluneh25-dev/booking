<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Reads the JSON-lines logs the application writes.
 *
 * Files are read newest first and only the tail of a large file is parsed, so
 * a review on a busy day stays bounded in memory and time.
 */
final class LogReader
{
    private const TAIL_BYTES = 2_097_152;

    private const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
    ];

    public function __construct(private readonly string $directory)
    {
    }

    /** @return list<array{name: string, bytes: int, modified: int}> */
    public function files(): array
    {
        $paths = glob($this->directory . DIRECTORY_SEPARATOR . 'app-*.log') ?: [];

        $files = [];
        foreach ($paths as $path) {
            $bytes = filesize($path);
            $modified = filemtime($path);
            if ($bytes === false || $modified === false) {
                continue;
            }

            $files[] = ['name' => basename($path), 'bytes' => $bytes, 'modified' => $modified];
        }

        usort($files, static fn (array $a, array $b): int => $b['modified'] <=> $a['modified']);

        return $files;
    }

    /** @return list<LogRecord> newest first */
    public function recent(string $minLevel = 'info', int $limit = 50): array
    {
        $threshold = self::rank($minLevel);
        $records = [];

        foreach ($this->files() as $file) {
            foreach ($this->records($file['name']) as $record) {
                if (self::rank($record->level) < $threshold) {
                    continue;
                }

                $records[] = $record;
                if (count($records) >= $limit) {
                    return $records;
                }
            }
        }

        return $records;
    }

    /** @return array<string, int> level => count within the window */
    public function counts(int $hours = 24): array
    {
        $cutoff = gmdate('Y-m-d', time() - max(1, $hours) * 3600);
        $counts = [];

        foreach ($this->files() as $file) {
            $date = substr($file['name'], 4, 10);
            if ($date < $cutoff) {
                continue;
            }

            foreach ($this->records($file['name']) as $record) {
                $counts[$record->level] = ($counts[$record->level] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function totalBytes(): int
    {
        $total = 0;
        foreach ($this->files() as $file) {
            $total += $file['bytes'];
        }

        return $total;
    }

    /** @return list<LogRecord> newest first within one file */
    private function records(string $name): array
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $name;
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $size = filesize($path);
        if ($size !== false && $size > self::TAIL_BYTES) {
            fseek($handle, -self::TAIL_BYTES, SEEK_END);
        }

        $contents = stream_get_contents($handle);
        fclose($handle);

        if (!is_string($contents)) {
            return [];
        }

        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];

        $records = [];
        foreach (array_reverse($lines) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }

            /** @var array<string, mixed> $decoded */
            $record = LogRecord::fromArray($decoded);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    public static function rank(string $level): int
    {
        return self::LEVELS[strtolower($level)] ?? 0;
    }

    public function oldestTimestamp(): ?DateTimeImmutable
    {
        $files = $this->files();
        $oldest = end($files);
        if ($oldest === false) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            substr($oldest['name'], 4, 10),
            new DateTimeZone('UTC'),
        );

        return $date === false ? null : $date;
    }
}
