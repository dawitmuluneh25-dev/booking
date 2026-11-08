<?php

declare(strict_types=1);

namespace App\Database;

use App\Support\BackupFile;
use Closure;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Logical backup and restore drill in pure PHP.
 *
 * `exec()` and `mysqldump` are disabled on much shared hosting, and a backup
 * that only works on the machine that wrote it is not a backup. This writes a
 * gzipped SQL script using nothing but PDO, then proves the script can be
 * imported by restoring it into a scratch schema and comparing row counts.
 *
 * The dump is destructive on import by design (`DROP TABLE IF EXISTS` precedes
 * each table), which is why a drill refuses to target the live database.
 */
final class DatabaseBackup
{
    private const INSERT_CHUNK = 100;

    /**
     * @param Closure(string): PDO $connectionFactory opens a connection to the
     *        given database name; the database must already exist.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $database,
        private readonly string $directory,
        private readonly Closure $connectionFactory,
    ) {
    }

    public function create(): BackupFile
    {
        $this->ensureDirectory();

        $name = 'booking-' . gmdate('Ymd-His') . '.sql.gz';
        $path = $this->directory . DIRECTORY_SEPARATOR . $name;
        $suffix = 1;
        while (is_file($path)) {
            $name = 'booking-' . gmdate('Ymd-His') . '-' . $suffix . '.sql.gz';
            $path = $this->directory . DIRECTORY_SEPARATOR . $name;
            $suffix++;
        }

        $handle = gzopen($path, 'wb9');
        if ($handle === false) {
            throw new RuntimeException('Cannot create the backup file.');
        }

        $tables = 0;
        $rows = 0;

        try {
            $this->write($handle, $this->header());

            // One consistent read view for every table in the dump.
            $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->beginTransaction();

            foreach ($this->tables() as $table) {
                $rows += $this->dumpTable($handle, $table);
                $tables++;
            }

            $this->pdo->commit();
            $this->write($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
            $this->write($handle, sprintf("-- tables: %d, rows: %d\n", $tables, $rows));
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            gzclose($handle);
            @unlink($path);

            throw $exception;
        }

        gzclose($handle);

        $bytes = filesize($path);

        return new BackupFile(
            path: $path,
            name: $name,
            bytes: $bytes === false ? 0 : $bytes,
            modifiedAt: time(),
            tables: $tables,
            rows: $rows,
        );
    }

    /**
     * @return list<BackupFile> newest first
     */
    public function all(int $limit = 20): array
    {
        $paths = glob($this->directory . DIRECTORY_SEPARATOR . '*.sql.gz') ?: [];

        $files = [];
        foreach ($paths as $path) {
            $bytes = filesize($path);
            $modified = filemtime($path);
            if ($bytes === false || $modified === false) {
                continue;
            }

            $files[] = new BackupFile($path, basename($path), $bytes, $modified);
        }

        usort($files, static fn (BackupFile $a, BackupFile $b): int => $b->modifiedAt <=> $a->modifiedAt);

        return array_slice($files, 0, max(1, $limit));
    }

    public function latest(): ?BackupFile
    {
        return $this->all(1)[0] ?? null;
    }

    /** Accepts an absolute path or a file name inside the backup directory. */
    public function open(string $pathOrName): BackupFile
    {
        $path = str_contains($pathOrName, DIRECTORY_SEPARATOR)
            ? $pathOrName
            : $this->directory . DIRECTORY_SEPARATOR . basename($pathOrName);

        if (!is_file($path)) {
            throw new RuntimeException('No backup file at ' . $path . '.');
        }

        $bytes = filesize($path);
        $modified = filemtime($path);

        return new BackupFile(
            path: $path,
            name: basename($path),
            bytes: $bytes === false ? 0 : $bytes,
            modifiedAt: $modified === false ? time() : $modified,
        );
    }

    /** Delete all but the newest $keep backups. @return int deleted count */
    public function prune(int $keep = 14): int
    {
        $keep = max(1, $keep);
        $deleted = 0;

        foreach (array_slice($this->all(1000), $keep) as $file) {
            if (@unlink($file->path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Restore the dump into a scratch schema and compare row counts.
     *
     * The target is dropped afterwards only when its name ends in `_drill`, so
     * a mistyped database name cannot delete production data.
     *
     * @return array{tables: int, rows: int, mismatches: list<string>, dropped: bool, target: string}
     */
    public function verify(BackupFile $file, ?string $target = null, bool $keep = false): array
    {
        $target = $target === null || $target === '' ? $this->database . '_drill' : $target;

        if ($target === $this->database) {
            throw new RuntimeException('Refusing to restore over the live database.');
        }

        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $target) !== 1) {
            throw new RuntimeException('Restore target must be a plain database name.');
        }

        $sql = $this->read($file);

        // An existing scratch database needs no DDL rights: most shared hosting
        // lets you create one from the control panel but not from SQL.
        $restored = $this->openTarget($target);

        foreach (SqlScript::split($sql) as $statement) {
            $restored->exec($statement);
        }

        $mismatches = [];
        $rows = 0;

        foreach ($this->tables() as $table) {
            $expected = $this->count($this->pdo, $table);
            $actual = $this->count($restored, $table);
            $rows += $actual;

            if ($expected !== $actual) {
                $mismatches[] = sprintf('%s: %d expected, %d restored', $table, $expected, $actual);
            }
        }

        $dropped = false;
        if (!$keep && str_ends_with($target, '_drill')) {
            $dropped = $this->dropTarget($target);
        }

        return [
            'tables' => count($this->tables()),
            'rows' => $rows,
            'mismatches' => $mismatches,
            'dropped' => $dropped,
            'target' => $target,
        ];
    }

    private function openTarget(string $target): PDO
    {
        try {
            return ($this->connectionFactory)($target);
        } catch (PDOException $exception) {
            // 1049 = unknown database, so it still has to be created.
            if (($exception->errorInfo[1] ?? null) !== 1049) {
                throw $exception;
            }
        }

        $admin = ($this->connectionFactory)('');
        $admin->exec('CREATE DATABASE IF NOT EXISTS ' . self::quoteIdentifier($target));

        return ($this->connectionFactory)($target);
    }

    private function dropTarget(string $target): bool
    {
        try {
            $admin = ($this->connectionFactory)('');
            $admin->exec('DROP DATABASE IF EXISTS ' . self::quoteIdentifier($target));

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    public static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /** @return list<string> */
    private function tables(): array
    {
        $statement = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        if ($statement === false) {
            throw new RuntimeException('Cannot list tables.');
        }

        $tables = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as $row) {
            if (is_array($row) && isset($row[0]) && is_string($row[0])) {
                $tables[] = $row[0];
            }
        }

        sort($tables, SORT_STRING);

        return $tables;
    }

    /** @param resource $handle */
    private function dumpTable($handle, string $table): int
    {
        $quoted = self::quoteIdentifier($table);

        $create = $this->pdo->query('SHOW CREATE TABLE ' . $quoted);
        if ($create === false) {
            throw new RuntimeException('Cannot describe table ' . $table . '.');
        }
        $definition = $create->fetch(PDO::FETCH_NUM);
        $ddl = is_array($definition) && isset($definition[1]) && is_string($definition[1])
            ? $definition[1]
            : null;

        if ($ddl === null) {
            throw new RuntimeException('Cannot describe table ' . $table . '.');
        }

        $this->write($handle, "\n-- table " . $table . "\n");
        $this->write($handle, 'DROP TABLE IF EXISTS ' . $quoted . ";\n");
        $this->write($handle, $ddl . ";\n");

        $rows = $this->pdo->query('SELECT * FROM ' . $quoted);
        if ($rows === false) {
            throw new RuntimeException('Cannot read table ' . $table . '.');
        }

        /** @var list<string>|null $columns */
        $columns = null;
        $header = '';
        /** @var list<string> $buffer */
        $buffer = [];
        $pending = 0;
        $total = 0;

        while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                continue;
            }

            if ($columns === null) {
                $columns = array_map(
                    static fn (int|string $column): string => (string) $column,
                    array_keys($row),
                );
                $header = 'INSERT INTO ' . $quoted . ' ('
                    . implode(', ', array_map(self::quoteIdentifier(...), $columns)) . ') VALUES';
            }

            $values = [];
            foreach ($columns as $column) {
                $values[] = $this->literal($row[$column] ?? null);
            }

            $buffer[] = '(' . implode(', ', $values) . ')';
            $pending++;
            $total++;

            if ($pending >= self::INSERT_CHUNK) {
                // Each chunk needs its own INSERT header.
                $this->write($handle, $header . "\n" . implode(",\n", $buffer) . ";\n");
                $buffer = [];
                $pending = 0;
            }
        }

        if ($buffer !== [] && $pending > 0) {
            $this->write($handle, $header . "\n" . implode(",\n", $buffer) . ";\n");
        }

        return $total;
    }

    private function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = is_scalar($value) ? (string) $value : '';
        $quoted = $this->pdo->quote($text);

        if ($quoted === false) {
            throw new RuntimeException('Cannot encode a value from the database.');
        }

        return $quoted;
    }

    private function count(PDO $pdo, string $table): int
    {
        $statement = $pdo->query('SELECT COUNT(*) FROM ' . self::quoteIdentifier($table));
        if ($statement === false) {
            return -1;
        }

        return (int) $statement->fetchColumn();
    }

    private function read(BackupFile $file): string
    {
        $handle = gzopen($file->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open the backup file.');
        }

        $contents = stream_get_contents($handle);
        gzclose($handle);

        if (!is_string($contents)) {
            throw new RuntimeException('Cannot read the backup file.');
        }

        return $contents;
    }

    private function header(): string
    {
        return implode("\n", [
            '-- Logical backup written by bin/console db:backup',
            '-- database: ' . $this->database,
            '-- generated: ' . gmdate('Y-m-d\TH:i:s\Z'),
            '-- Restoring drops and recreates every table below.',
            'SET NAMES utf8mb4;',
            'SET FOREIGN_KEY_CHECKS = 0;',
            '',
        ]);
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create the backup directory.');
        }
    }

    /** @param resource $handle */
    private function write($handle, string $data): void
    {
        if (gzwrite($handle, $data) === false) {
            throw new RuntimeException('Cannot write to the backup file.');
        }
    }
}
