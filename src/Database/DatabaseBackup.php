<?php

declare(strict_types=1);

namespace App\Database;

use App\Support\BackupCipher;
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

    /** Built on first use, so a malformed key cannot break a caller that never writes or reads a dump. */
    private ?BackupCipher $cipherCache = null;

    /**
     * @param Closure(string): PDO $connectionFactory opens a connection to the
     *        given database name; the database must already exist.
     * @param string|null $encryptionKey `BACKUP_ENCRYPTION_KEY`, base64. When set, dumps are
     *        written encrypted; when null they are written as plain gzipped SQL, which the
     *        console reports every time.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $database,
        private readonly string $directory,
        private readonly Closure $connectionFactory,
        private readonly ?string $encryptionKey = null,
    ) {
    }

    /**
     * Where the backups live.
     *
     * Exposed so a failure record can be written beside the archives it explains: an operator looking
     * for a backup finds the reason there is not a new one in the same directory, rather than having to
     * know a second path.
     */
    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * A file in the backup directory that is a finished backup.
     *
     * `.part` is excluded deliberately: a dump that is still being written must never be offered
     * as the newest backup, because `latest()` feeds the restore drill and the drill would then
     * restore a truncated file and report a row-count mismatch instead of a missing backup.
     *
     * Public because it is the rule that decides what `prune()` may delete, and it can be tested
     * without a database connection.
     */
    public static function isBackupName(string $name): bool
    {
        return preg_match('/\Abooking-\d{8}-\d{6}(?:-\d+)?\.sql\.gz(?:\.enc)?\z/', $name) === 1;
    }

    public function create(): BackupFile
    {
        $this->ensureDirectory();

        $encrypted = $this->encryptionKey !== null;
        $extension = 'sql.gz' . ($encrypted ? '.enc' : '');

        $name = 'booking-' . gmdate('Ymd-His') . '.' . $extension;
        $path = $this->directory . DIRECTORY_SEPARATOR . $name;
        $suffix = 1;
        while (is_file($path) || is_file($path . '.part')) {
            $name = 'booking-' . gmdate('Ymd-His') . '-' . $suffix . '.' . $extension;
            $path = $this->directory . DIRECTORY_SEPARATOR . $name;
            $suffix++;
        }

        // Written under a `.part` name and moved into place only once it is complete. Before this,
        // a process killed mid-dump left a truncated `.sql.gz` that `latest()` returned as the
        // newest backup — a file that looks like a backup and restores to half a database.
        $part = $path . '.part';

        $handle = gzopen($part, 'wb9');
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
            @unlink($part);

            throw $exception;
        }

        gzclose($handle);

        if ($encrypted) {
            try {
                $this->cipher()->encryptFile($part, $path);
            } catch (Throwable $exception) {
                // A half-encrypted file is not a backup, and leaving it would let `all()` list a
                // file that cannot be decrypted. Both names go.
                @unlink($part);
                @unlink($path);

                throw $exception;
            }

            @unlink($part);
        } elseif (!@rename($part, $path)) {
            @unlink($part);

            throw new RuntimeException('Cannot move the finished backup into place at ' . $path . '.');
        }

        $bytes = filesize($path);

        return new BackupFile(
            path: $path,
            name: $name,
            bytes: $bytes === false ? 0 : $bytes,
            modifiedAt: time(),
            tables: $tables,
            rows: $rows,
            encrypted: $encrypted,
        );
    }

    /**
     * @return list<BackupFile> newest first
     */
    public function all(int $limit = 20): array
    {
        $paths = glob($this->directory . DIRECTORY_SEPARATOR . '*.sql.gz*') ?: [];

        $files = [];
        foreach ($paths as $path) {
            $name = basename($path);

            if (!self::isBackupName($name)) {
                continue;
            }

            $bytes = filesize($path);
            $modified = filemtime($path);
            if ($bytes === false || $modified === false) {
                continue;
            }

            $files[] = new BackupFile(
                $path,
                $name,
                $bytes,
                $modified,
                encrypted: BackupCipher::looksEncrypted($path),
            );
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
            // A person reading `booking-….sql.gz.enc` off the disk is as likely to type the name
            // without the suffix, so one silent retry rather than an error about a file that is there.
            $alternate = str_ends_with($path, '.enc') ? substr($path, 0, -4) : $path . '.enc';

            if (is_file($alternate)) {
                $path = $alternate;
            } else {
                throw new RuntimeException('No backup file at ' . $path . '.');
            }
        }

        $bytes = filesize($path);
        $modified = filemtime($path);

        return new BackupFile(
            path: $path,
            name: basename($path),
            bytes: $bytes === false ? 0 : $bytes,
            modifiedAt: $modified === false ? time() : $modified,
            encrypted: BackupCipher::looksEncrypted($path),
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

        $columns = $this->writableColumnsFor($table);

        // A table whose every column is generated has nothing to insert; the DDL
        // written above is the whole of its restore.
        if ($columns === []) {
            return 0;
        }

        $select = 'SELECT ' . implode(', ', array_map(self::quoteIdentifier(...), $columns))
            . ' FROM ' . $quoted;
        $rows = $this->pdo->query($select);
        if ($rows === false) {
            throw new RuntimeException('Cannot read table ' . $table . '.');
        }

        $header = 'INSERT INTO ' . $quoted . ' ('
            . implode(', ', array_map(self::quoteIdentifier(...), $columns)) . ') VALUES';
        /** @var list<string> $buffer */
        $buffer = [];
        $pending = 0;
        $total = 0;

        while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                continue;
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

    /** @return list<string> */
    private function writableColumnsFor(string $table): array
    {
        $statement = $this->pdo->query('SHOW FULL COLUMNS FROM ' . self::quoteIdentifier($table));

        if ($statement === false) {
            throw new RuntimeException('Cannot describe table ' . $table . '.');
        }

        /** @var list<array<string, mixed>> $definition */
        $definition = $statement->fetchAll(PDO::FETCH_ASSOC);

        return self::writableColumns($definition);
    }

    /**
     * The columns an INSERT may name.
     *
     * Generated columns are excluded. `published_content.live_key` is
     * `GENERATED ALWAYS AS (CASE WHEN status = 'published' …) STORED`, and naming it
     * in an INSERT makes MariaDB refuse the statement outright — `1906 The value
     * specified for generated column 'live_key' … has been ignored` — which is how a
     * dump that looked complete turned out to be unrestorable. MySQL raises 3105 for
     * the same statement. The dump carried the computed value because `SELECT *`
     * expands to generated columns too, so the fix has to start at the column list,
     * not at the row.
     *
     * @param list<array<string, mixed>> $definition rows from SHOW FULL COLUMNS
     * @return list<string>
     */
    public static function writableColumns(array $definition): array
    {
        $columns = [];

        foreach ($definition as $column) {
            if (!is_array($column)) {
                continue;
            }

            $name = $column['Field'] ?? null;
            $extra = $column['Extra'] ?? '';

            if (!is_string($name) || $name === '') {
                continue;
            }

            // `STORED GENERATED` / `VIRTUAL GENERATED` only. A plain substring match
            // would also throw out `DEFAULT_GENERATED`, which MySQL uses for an
            // ordinary column with an expression default — a real column that must
            // stay in the dump, or its values are lost on restore.
            if (is_string($extra) && preg_match('/(?:STORED|VIRTUAL) GENERATED/i', $extra) === 1) {
                continue;
            }

            $columns[] = $name;
        }

        return $columns;
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

    /**
     * The dump, decrypted when the file carries our magic.
     *
     * Dispatch is by content, not by name: a file renamed to `.sql.gz` after being encrypted would
     * otherwise be fed to `gzopen`, which fails on the magic bytes with an error that says nothing
     * about the real problem.
     */
    private function read(BackupFile $file): string
    {
        if (BackupCipher::looksEncrypted($file->path)) {
            // Decryption yields the *gzip member* that was encrypted, not SQL. Both branches below
            // have to end at the same place — the SQL script — because `verify()` splits the return
            // value into statements. Returning gzip here and SQL on the plaintext path is a bug that
            // shipped once: the cipher round-trip test passed, the restore path did not, and nothing
            // noticed until a real server was available to write a real dump.
            $compressed = $this->cipher()->decryptContents($file->path);
            $sql = gzdecode($compressed);

            if ($sql === false) {
                throw new RuntimeException(
                    'The backup decrypted correctly but is not gzip data, so it is not a dump this '
                    . 'application wrote. Refusing rather than restoring whatever it is.',
                );
            }

            return $sql;
        }

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

    private function cipher(): BackupCipher
    {
        if ($this->encryptionKey === null) {
            throw new RuntimeException(
                'That backup is encrypted and BACKUP_ENCRYPTION_KEY is not set. Without the key the file '
                . 'cannot be restored — which is the property the encryption is there to have, and the '
                . 'reason the key needs custody of its own rather than a copy beside the database password.',
            );
        }

        return $this->cipherCache ??= BackupCipher::fromBase64($this->encryptionKey);
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
