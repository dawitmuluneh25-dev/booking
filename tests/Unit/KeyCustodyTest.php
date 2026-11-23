<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\KeyCustody;
use PHPUnit\Framework\TestCase;

/**
 * D-08's custody controls, asserted against a real filesystem.
 *
 * These are properties of files, so the test makes files: a document root, a backup directory, an
 * environment file, and a recovery copy in the wrong place and then the right one. A test that
 * passed these as strings would prove nothing about `isInside()`, which is the only interesting part.
 *
 * Permission modes are asserted on POSIX only. On Windows `chmod` does not map to an ACL and
 * `fileperms()` returns a synthesised value, so the check reports a warning there and this test skips
 * rather than asserting a mode the platform does not implement. CI runs Linux; the skip is for the
 * developer's Windows workstation, where the alternative is a test that lies.
 */
final class KeyCustodyTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'custody-' . bin2hex(random_bytes(5));

        mkdir($this->root . '/public', 0775, true);
        mkdir($this->root . '/var/backups', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    /** @return array{check: string, state: string, detail: string} */
    private function find(KeyCustody $custody, string $name): array
    {
        foreach ($custody->checks() as $check) {
            if ($check['check'] === $name) {
                return $check;
            }
        }

        self::fail('No check named "' . $name . '".');
    }

    private function envFile(): string
    {
        $path = $this->root . '/.env';
        file_put_contents($path, "BACKUP_ENCRYPTION_KEY=not-a-real-key\n");

        return $path;
    }

    private function custody(?string $envFile = null, ?string $recovery = null, bool $key = true): KeyCustody
    {
        return new KeyCustody(
            $this->root . '/public',
            $this->root . '/var/backups',
            $envFile,
            $recovery,
            $key,
        );
    }

    public function testAnUnsetKeyFailsTheFirstCheck(): void
    {
        $custody = $this->custody(null, null, false);

        self::assertSame('fail', $this->find($custody, 'Backup key')['state']);
        self::assertTrue($custody->hasFailure());
    }

    public function testAKeyInsideTheDocumentRootFails(): void
    {
        $inside = $this->root . '/public/.env';
        file_put_contents($inside, "BACKUP_ENCRYPTION_KEY=not-a-real-key\n");

        self::assertSame('fail', $this->find($this->custody($inside), 'Outside the web root')['state']);
    }

    public function testAKeyAboveTheDocumentRootPasses(): void
    {
        $env = $this->envFile();

        self::assertSame('ok', $this->find($this->custody($env), 'Outside the web root')['state']);
    }

    public function testAKeyFileReadableByTheGroupOrOthersFails(): void
    {
        $env = $this->envFile();

        // CI runs Linux and asserts the real mode. On Windows the same test asserts the other half of
        // the contract — that the check refuses to claim a pass it cannot observe — instead of
        // skipping, because this project's CI fails a build that contains a skipped test.
        if (PHP_OS_FAMILY === 'Windows') {
            $check = $this->find($this->custody($env), 'Key file mode');

            self::assertSame('warn', $check['state']);
            self::assertStringContainsString('not ACLs', $check['detail']);

            return;
        }

        chmod($env, 0644);

        $check = $this->find($this->custody($env), 'Key file mode');

        self::assertSame('fail', $check['state']);
        self::assertStringContainsString('0644', $check['detail']);
    }

    public function testAKeyFileAtSixHundredPasses(): void
    {
        $env = $this->envFile();

        if (PHP_OS_FAMILY === 'Windows') {
            $check = $this->find($this->custody($env), 'Key file mode');

            self::assertSame('warn', $check['state'], 'Windows must not claim 0600 is verified.');
            self::assertStringContainsString('check the account permissions by hand', $check['detail']);

            return;
        }

        chmod($env, 0600);

        self::assertSame('ok', $this->find($this->custody($env), 'Key file mode')['state']);
    }

    public function testKeyShapedMaterialBesideTheArchivesFails(): void
    {
        file_put_contents($this->root . '/var/backups/backup-key.txt', 'not-a-real-key');

        $check = $this->find($this->custody($this->envFile()), 'Key apart from archives');

        self::assertSame('fail', $check['state']);
        self::assertStringContainsString('backup-key.txt', $check['detail']);
    }

    public function testNoRecoveryCopyIsAWarningRatherThanASilentPass(): void
    {
        $check = $this->find($this->custody($this->envFile()), 'Recovery copy');

        self::assertSame('warn', $check['state']);
        self::assertStringContainsString('losing this host loses every archive', $check['detail']);
    }

    public function testARecoveryCopyInsideTheBackupDirectoryFails(): void
    {
        mkdir($this->root . '/var/backups/recovery', 0775, true);
        $recovery = $this->root . '/var/backups/recovery/key';
        file_put_contents($recovery, 'not-a-real-key');

        self::assertSame('fail', $this->find($this->custody($this->envFile(), $recovery), 'Recovery copy')['state']);
    }

    public function testTheRecoveryCopyCannotBeTheActiveKey(): void
    {
        $env = $this->envFile();

        self::assertSame('fail', $this->find($this->custody($env, $env), 'Recovery copy')['state']);
    }

    public function testARecoveryCopyInASeparateProtectedPlacePasses(): void
    {
        mkdir($this->root . '/recovery', 0700, true);
        $recovery = $this->root . '/recovery/key';
        file_put_contents($recovery, 'not-a-real-key');

        $custody = $this->custody($this->envFile(), $recovery);

        self::assertSame('ok', $this->find($custody, 'Recovery copy')['state']);
        self::assertFalse($custody->hasFailure());
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($child)) {
                $this->remove($child);
                continue;
            }

            @unlink($child);
        }

        @rmdir($path);
    }
}
