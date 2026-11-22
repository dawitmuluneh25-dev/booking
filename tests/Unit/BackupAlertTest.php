<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\BackupAlert;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The record a failed scheduled backup leaves behind.
 *
 * Two things here have a consequence beyond the file: the alert must never carry a secret, because a
 * dump failure message is exactly the sort of text that quotes a connection string; and a failure the
 * next run made good must stop being an alert, or `diag` would report a broken backup pipeline for as
 * long as the file exists.
 */
final class BackupAlertTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/backup-alert-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        $path = BackupAlert::path($this->dir);

        if (is_file($path)) {
            unlink($path);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testNoRecordMeansNoAlert(): void
    {
        self::assertNull(BackupAlert::latest($this->dir));
        self::assertFalse(BackupAlert::outranksSuccess(null, time()));
    }

    public function testAFailureIsWrittenBesideTheBackupsAndReadBack(): void
    {
        BackupAlert::record(
            $this->dir,
            'key-missing',
            'BACKUP_ENCRYPTION_KEY is empty',
            $this->at('2026-11-23T06:00:00Z'),
        );

        self::assertSame($this->dir . DIRECTORY_SEPARATOR . BackupAlert::FILE, BackupAlert::path($this->dir));
        self::assertFileExists(BackupAlert::path($this->dir));

        $latest = BackupAlert::latest($this->dir);

        self::assertIsArray($latest);
        self::assertSame('key-missing', $latest['reason']);
        self::assertSame('2026-11-23T06:00:00Z', $latest['at']);
        self::assertSame('BACKUP_ENCRYPTION_KEY is empty', $latest['detail']);
    }

    public function testTheNewestFailureWins(): void
    {
        BackupAlert::record($this->dir, 'key-missing', 'first', $this->at('2026-11-23T06:00:00Z'));
        BackupAlert::record($this->dir, 'dump-failed', 'second', $this->at('2026-11-23T07:00:00Z'));

        $latest = BackupAlert::latest($this->dir);

        self::assertIsArray($latest);
        self::assertSame('dump-failed', $latest['reason']);
    }

    public function testATruncatedLineIsSkippedRatherThanBelieved(): void
    {
        BackupAlert::record($this->dir, 'dump-failed', 'whole', $this->at('2026-11-23T07:00:00Z'));

        // An interrupted append leaves half a line; it must not become the newest alert.
        file_put_contents(BackupAlert::path($this->dir), '{"at":"2026-11-23T08:00:00Z","rea', FILE_APPEND);

        $latest = BackupAlert::latest($this->dir);

        self::assertIsArray($latest);
        self::assertSame('dump-failed', $latest['reason']);
    }

    public function testASecretInTheFailureMessageIsRedacted(): void
    {
        BackupAlert::record(
            $this->dir,
            'dump-failed',
            'mysqldump rejected credentials for BACKUP_ENCRYPTION_KEY=Zm9vYmFyYmF6cXV1eGNvcmdlZ3JhdWx0Z2FycGx5',
            $this->at('2026-11-23T07:00:00Z'),
        );

        $path = BackupAlert::path($this->dir);
        $raw = (string) file_get_contents($path);

        self::assertStringNotContainsString('Zm9vYmFyYmF6cXV1eGNvcmdlZ3JhdWx0Z2FycGx5', $raw);

        $latest = BackupAlert::latest($this->dir);

        self::assertIsArray($latest);
        self::assertStringContainsString('[redacted]', $latest['detail']);
    }

    public function testTheDetailStaysOnOneLine(): void
    {
        BackupAlert::record(
            $this->dir,
            'dump-failed',
            "line one\nline two\r\nline three",
            $this->at('2026-11-23T07:00:00Z'),
        );

        $latest = BackupAlert::latest($this->dir);

        self::assertIsArray($latest);
        self::assertStringNotContainsString("\n", $latest['detail']);
        self::assertSame('line one line two line three', $latest['detail']);
    }

    /**
     * The rule that keeps `diag` honest: a failure is an alert only until something succeeds after it.
     */
    public function testAFailureFollowedByASuccessIsNoLongerAnAlert(): void
    {
        $alertAt = '2026-11-23T06:00:00Z';
        $alert = $this->at($alertAt)->getTimestamp();

        self::assertTrue(
            BackupAlert::outranksSuccess($alertAt, $alert - 60),
            'a failure after the last success is an alert',
        );
        self::assertFalse(BackupAlert::outranksSuccess($alertAt, $alert + 60), 'a later success retires the alert');
        self::assertTrue(BackupAlert::outranksSuccess($alertAt, null), 'no success at all leaves the alert standing');
    }

    public function testAnUnparseableTimeDoesNotRaiseAnAlert(): void
    {
        self::assertFalse(BackupAlert::outranksSuccess('yesterday-ish', time()));
    }

    private function at(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }
}
