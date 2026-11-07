<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

/**
 * Forward-only SQL migration runner.
 *
 * Files are applied in filename order and recorded with a SHA-256 checksum so
 * an edited migration is detected instead of silently reapplied.
 *
 * MySQL DDL can implicitly commit; a failed file is therefore reported as
 * partially applied and requires operator review rather than automatic retry.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    public function ensureLedger(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(20) NOT NULL,
                name VARCHAR(190) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * @return list<array{version: string, name: string, applied: bool, checksum_ok: bool}>
     */
    public function status(): array
    {
        $this->ensureLedger();
        $applied = $this->appliedMap();

        $rows = [];
        foreach ($this->files() as $file) {
            $version = $this->version($file);
            $checksum = $this->checksum($file);
            $rows[] = [
                'version' => $version,
                'name' => basename($file),
                'applied' => isset($applied[$version]),
                'checksum_ok' => !isset($applied[$version]) || hash_equals($applied[$version], $checksum),
            ];
        }

        return $rows;
    }

    /**
     * @return list<string> applied file names
     */
    public function migrate(bool $dryRun = false): array
    {
        $this->ensureLedger();
        $applied = $this->appliedMap();
        $done = [];

        foreach ($this->files() as $file) {
            $version = $this->version($file);
            $name = basename($file);
            $checksum = $this->checksum($file);

            if (isset($applied[$version])) {
                if (!hash_equals($applied[$version], $checksum)) {
                    throw new RuntimeException(
                        'Checksum drift detected for applied migration ' . $name . '. Do not edit applied migrations.'
                    );
                }

                continue;
            }

            if ($dryRun) {
                $done[] = $name . ' (dry-run)';
                continue;
            }

            foreach ($this->statements($file) as $statement) {
                $this->pdo->exec($statement);
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO schema_migrations (version, name, checksum, applied_at)
                 VALUES (:version, :name, :checksum, UTC_TIMESTAMP())'
            );
            $insert->execute([
                'version' => $version,
                'name' => $name,
                'checksum' => $checksum,
            ]);

            $done[] = $name;
        }

        return $done;
    }

    /** @return array<string, string> version => checksum */
    private function appliedMap(): array
    {
        $statement = $this->pdo->query('SELECT version, checksum FROM schema_migrations');
        $rows = $statement === false ? [] : $statement->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['version'], $row['checksum'])) {
                $map[(string) $row['version']] = (string) $row['checksum'];
            }
        }

        return $map;
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files, SORT_STRING);

        return $files;
    }

    private function version(string $file): string
    {
        if (preg_match('/^(\d{4})_[a-z0-9_]+\.sql$/', basename($file), $matches) !== 1) {
            throw new RuntimeException('Invalid migration filename: ' . basename($file));
        }

        return $matches[1];
    }

    private function checksum(string $file): string
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException('Cannot read migration: ' . basename($file));
        }

        return hash('sha256', $contents);
    }

    /**
     * Split a file into statements, ignoring semicolons inside quotes and
     * comments. Deliberately unsupported: stored procedures and DELIMITER.
     *
     * @return list<string>
     */
    private function statements(string $file): array
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Cannot read migration: ' . basename($file));
        }

        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $quote !== '`') {
                    $buffer .= $next;
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                continue;
            }

            if ($char === '#' && $buffer === '') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i);
                $i = $end === false ? $length : $end + 1;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === ';') {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}
