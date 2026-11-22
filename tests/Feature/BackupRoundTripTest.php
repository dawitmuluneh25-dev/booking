<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Database\DatabaseBackup;
use App\Support\BackupCipher;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * `db:backup` against a real server, encrypted and not.
 *
 * The dump path had no automated test until this one. The evidence behind "the backup restores" was a
 * manual drill on the host on 22 November, which is good evidence and not a regression test: it would not
 * notice the day somebody changed the output directory, dropped the `.part` rename, or wired the key into
 * the wrong constructor argument. The failures that matter here are silent ones — a file that exists and
 * cannot be decrypted, or a half-written dump that `latest()` offers to the restore drill.
 *
 * What this test does not do is restore: `verify()` needs a scratch schema, and the account running the
 * suite is not guaranteed `CREATE DATABASE`. The restore half stays as proven by the 22 November host
 * drill (51 tables, 78 rows) until a job runs somewhere that can create one.
 */
final class BackupRoundTripTest extends TestCase
{
    private ?PDO $pdo = null;

    private ?Config $config = null;

    private string $directory = '';

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        Env::load($root . '/.env');
        $this->config = new Config($root);

        try {
            $this->pdo = Connection::create($this->config->database());
        } catch (PDOException | RuntimeException $exception) {
            self::markTestSkipped('MySQL is not reachable: ' . $exception->getMessage());
        }

        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hr-dbbackup-' . bin2hex(random_bytes(6));

        if (!mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            self::fail('Cannot create a scratch backup directory.');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    public function testAnUnkeyedBackupIsWrittenInOneMovedFile(): void
    {
        $backup = $this->backup(null);
        $file = $backup->create();

        self::assertStringEndsWith('.sql.gz', $file->name, 'Without a key the name must not claim encryption.');
        self::assertFalse($file->encrypted);
        self::assertGreaterThan(0, $file->tables);
        self::assertFileExists($file->path);

        // The `.part` is the point: a dump in progress must not be visible as a backup.
        self::assertSame([], glob($this->directory . DIRECTORY_SEPARATOR . '*.part') ?: []);

        $sql = $this->readPlain($file->path);
        self::assertStringContainsString('-- Logical backup written by bin/console db:backup', $sql);
        self::assertStringContainsString('CREATE TABLE', $sql);
        self::assertStringContainsString(sprintf('-- tables: %d, rows: %d', $file->tables, $file->rows), $sql);
    }

    public function testAKeyedBackupIsEncryptedAndStillContainsTheDump(): void
    {
        $key = BackupCipher::generateKey();
        $file = $this->backup($key)->create();

        self::assertStringEndsWith('.sql.gz.enc', $file->name);
        self::assertTrue($file->encrypted);
        self::assertTrue(BackupCipher::looksEncrypted($file->path));

        $bytes = (string) file_get_contents($file->path);
        self::assertStringNotContainsString('CREATE TABLE', $bytes, 'The dump is readable without the key.');

        // Decryption yields the gzip member that was encrypted; the SQL is one gunzip further on.
        // Asserting on the decrypted bytes without this step is what hid a defect in the restore
        // path: the file decrypted fine and restored to gzip.
        $sql = gzdecode(BackupCipher::fromBase64($key)->decryptContents($file->path));
        self::assertIsString($sql, 'The decrypted backup is not gzip data.');
        self::assertStringContainsString('CREATE TABLE', $sql);
        self::assertStringContainsString(sprintf('-- tables: %d, rows: %d', $file->tables, $file->rows), $sql);

        self::assertSame([], glob($this->directory . DIRECTORY_SEPARATOR . '*.part') ?: []);
    }

    public function testTheListingIgnoresWorkInProgressAndReportsEncryption(): void
    {
        $backup = $this->backup(BackupCipher::generateKey());
        $file = $backup->create();

        // A file left by a killed run, and a copy somebody dropped in by hand.
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'booking-20200101-000000.sql.gz.part', 'x');
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'someone-elses-copy.sql.gz', 'x');

        $names = array_map(static fn ($listed): string => $listed->name, $backup->all(50));

        self::assertSame([$file->name], $names);

        $latest = $backup->latest();
        self::assertNotNull($latest);
        self::assertSame($file->name, $latest->name);
        self::assertTrue($latest->encrypted);
    }

    public function testAnEncryptedFileCannotBeReadWithoutItsKey(): void
    {
        // The property encryption is supposed to have. A missing key must stop the restore, not
        // quietly produce an empty script that "restores" nothing and reports success.
        $file = $this->backup(BackupCipher::generateKey())->create();
        $withoutKey = $this->backup(null);

        $read = new ReflectionMethod(DatabaseBackup::class, 'read');
        $read->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BACKUP_ENCRYPTION_KEY is not set');

        $read->invoke($withoutKey, $withoutKey->open($file->name));
    }

    private function backup(?string $key): DatabaseBackup
    {
        $pdo = $this->pdo;
        $config = $this->config;

        if ($pdo === null || $config === null) {
            self::fail('The fixture is not connected to a database.');
        }

        $current = $config->database();

        return new DatabaseBackup(
            $pdo,
            $current['database'],
            $this->directory,
            static function (string $target) use ($config): PDO {
                $configuration = $config->database();
                $configuration['database'] = $target;

                return Connection::create($configuration, $target === '');
            },
            $key,
        );
    }

    private function readPlain(string $path): string
    {
        $handle = gzopen($path, 'rb');
        self::assertNotFalse($handle);

        $contents = stream_get_contents($handle);
        gzclose($handle);

        self::assertIsString($contents);

        return $contents;
    }
}
