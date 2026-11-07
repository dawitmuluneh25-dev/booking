<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Append-only JSON-lines logger. One file per day, outside the web root.
 */
final class Logger
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $record = [
            'ts' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.vP'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            $line = '{"level":"error","message":"log encoding failed"}';
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Log directory is not writable.');
        }

        $file = $this->directory . DIRECTORY_SEPARATOR . 'app-' . gmdate('Y-m-d') . '.log';
        file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
