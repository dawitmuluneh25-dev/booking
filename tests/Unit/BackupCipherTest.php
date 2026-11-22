<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\DatabaseBackup;
use App\Support\BackupCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The backup is unreadable without its key, and a damaged one is refused rather than half-restored.
 *
 * §9 of HR-OPS-REF-R1-001 requires encrypted backups. The interesting failures are not "does RSA work" —
 * they are the ones that let a bad file through: a truncated dump, a single flipped byte, a key that is
 * merely the wrong key rather than absent. Each of those is a separate test because each would otherwise
 * restore *something* and report success against row counts that happen to match.
 */
final class BackupCipherTest extends TestCase
{
    private const MARKER = 'SECRET-CLINICAL-MARKER-9f3a';

    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hr-backup-' . bin2hex(random_bytes(6));

        if (!mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            self::fail('Cannot create a scratch directory for the test.');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    public function testAFileSurvivesTheRoundTripAcrossChunkBoundaries(): void
    {
        // 700 KiB is three records at the 256 KiB chunk size, so the last record is short and the
        // middle ones are full — the shape a real dump has.
        $plain = str_repeat(self::MARKER . "\n", 40000);
        $source = $this->write('source.bin', $plain);
        $target = $this->path('encrypted.bin');

        $cipher = new BackupCipher(random_bytes(BackupCipher::KEY_BYTES));
        $cipher->encryptFile($source, $target);

        self::assertTrue(BackupCipher::looksEncrypted($target));
        self::assertSame(strlen($plain), strlen($cipher->decryptContents($target)));
        self::assertSame($plain, $cipher->decryptContents($target));
    }

    public function testThePlaintextIsNotInTheFile(): void
    {
        $source = $this->write('source.bin', str_repeat(self::MARKER . "\n", 10));
        $target = $this->path('encrypted.bin');

        (new BackupCipher(random_bytes(BackupCipher::KEY_BYTES)))->encryptFile($source, $target);

        $bytes = (string) file_get_contents($target);

        self::assertStringNotContainsString(self::MARKER, $bytes, 'The marker survived encryption.');
        self::assertStringStartsWith(BackupCipher::MAGIC, $bytes);
    }

    public function testAnEmptyFileStillProducesARestorableBackup(): void
    {
        // A schema-only dump is a legitimate dump. Without a final record it would decrypt to
        // "truncated", and the operator would go looking for a corruption that is not there.
        $source = $this->write('source.bin', '');
        $target = $this->path('encrypted.bin');

        $cipher = new BackupCipher(random_bytes(BackupCipher::KEY_BYTES));
        $cipher->encryptFile($source, $target);

        self::assertSame('', $cipher->decryptContents($target));
    }

    public function testAWrongKeyIsRefused(): void
    {
        $source = $this->write('source.bin', self::MARKER);
        $target = $this->path('encrypted.bin');

        (new BackupCipher(random_bytes(BackupCipher::KEY_BYTES)))->encryptFile($source, $target);

        // A wrong key and a tampered file are indistinguishable at this point, and that is correct:
        // both mean the plaintext cannot be vouched for. The message says so rather than guessing.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed authentication');

        (new BackupCipher(random_bytes(BackupCipher::KEY_BYTES)))->decryptContents($target);
    }

    public function testOneFlippedByteIsRefused(): void
    {
        $source = $this->write('source.bin', str_repeat(self::MARKER . "\n", 2000));
        $target = $this->path('encrypted.bin');

        $cipher = new BackupCipher(random_bytes(BackupCipher::KEY_BYTES));
        $cipher->encryptFile($source, $target);

        $bytes = (string) file_get_contents($target);
        $offset = strlen(BackupCipher::MAGIC) + 24 + 4 + 100;
        $bytes[$offset] = chr(ord($bytes[$offset]) ^ 0x01);
        file_put_contents($target, $bytes);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed authentication');

        $cipher->decryptContents($target);
    }

    public function testATruncatedFileIsRefused(): void
    {
        $source = $this->write('source.bin', str_repeat(self::MARKER . "\n", 2000));
        $target = $this->path('encrypted.bin');

        $cipher = new BackupCipher(random_bytes(BackupCipher::KEY_BYTES));
        $cipher->encryptFile($source, $target);

        $bytes = (string) file_get_contents($target);
        // Cut the final record off entirely: the file now ends mid-stream with no TAG_FINAL.
        file_put_contents($target, substr($bytes, 0, -20));

        $this->expectException(RuntimeException::class);

        $cipher->decryptContents($target);
    }

    public function testAFileThatIsNotOursIsRefusedRatherThanGuessedAt(): void
    {
        $target = $this->write('plain.sql.gz', "\x1f\x8b\x08\x00 not really gzip");

        self::assertFalse(BackupCipher::looksEncrypted($target));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a HakimeRAD encrypted backup');

        (new BackupCipher(random_bytes(BackupCipher::KEY_BYTES)))->decryptContents($target);
    }

    public function testTheGeneratedKeyIsTheOneTheParserAccepts(): void
    {
        // The generator and the parser must agree. Otherwise an operator follows the instruction
        // exactly and discovers at 02:00 that the format was different.
        $cipher = BackupCipher::fromBase64(BackupCipher::generateKey());

        $source = $this->write('source.bin', self::MARKER);
        $target = $this->path('encrypted.bin');
        $cipher->encryptFile($source, $target);

        self::assertSame(self::MARKER, $cipher->decryptContents($target));
    }

    public function testTheKeyParserRejectsWhatPeopleActuallyPaste(): void
    {
        $bad = [
            'empty' => '',
            'whitespace' => '   ',
            'not base64' => 'not base64 !!',
            'too short' => sodium_bin2base64('too short', SODIUM_BASE64_VARIANT_ORIGINAL),
        ];

        foreach ($bad as $label => $value) {
            try {
                BackupCipher::fromBase64($value);
                self::fail('fromBase64 accepted the ' . $label . ' key.');
            } catch (RuntimeException $exception) {
                self::assertNotSame('', $exception->getMessage(), $label . ' produced an empty message.');
            }
        }
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function backupNames(): array
    {
        return [
            'plain' => ['booking-20261122-010101.sql.gz', true],
            'encrypted' => ['booking-20261122-010101.sql.gz.enc', true],
            'collision suffix' => ['booking-20261122-010101-1.sql.gz', true],
            'encrypted collision suffix' => ['booking-20261122-010101-2.sql.gz.enc', true],
            'in progress' => ['booking-20261122-010101.sql.gz.part', false],
            'in progress, encrypted' => ['booking-20261122-010101.sql.gz.enc.part', false],
            'someone else copy' => ['backup-copy.sql.gz', false],
            'not a dump' => ['booking-20261122-010101.sql', false],
            'a directory name' => ['booking-20261122-010101.sql.gz.d', false],
        ];
    }

    #[DataProvider('backupNames')]
    public function testOnlyFinishedBackupsCount(string $name, bool $expected): void
    {
        // This rule decides what `all()` lists and therefore what `prune()` may delete. A `.part`
        // that counted would let `latest()` hand the restore drill a half-written file.
        self::assertSame($expected, DatabaseBackup::isBackupName($name));
    }

    private function path(string $name): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $name;
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->path($name);
        file_put_contents($path, $contents);

        return $path;
    }
}
