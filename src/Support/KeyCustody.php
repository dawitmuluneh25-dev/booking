<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether `BACKUP_ENCRYPTION_KEY` is held the way D-08 says it must be.
 *
 * D-08 is a custody decision, not a crypto one: the encryption already works (§30). What it asks for
 * is that the key lives outside the public document root, is readable only by the account that needs
 * it, is not sitting beside the archives it unlocks, and has an independent recovery copy in a
 * separate protected place. Each of those is a property of the filesystem, which means each of them
 * can regress silently when somebody copies a directory or restores a backup with default modes.
 *
 * So this class answers one question per check and nothing else. It reads; it never writes, and it
 * never prints the key — a custody report that quoted the secret would be the leak it exists to
 * prevent.
 *
 * Two limits are stated here rather than discovered later:
 *
 *   - **File modes are POSIX.** On Windows `fileperms()` returns a synthesised value that does not
 *     describe an ACL, so the permission check reports `warn` with that reason instead of claiming a
 *     pass it cannot observe.
 *   - **A key inside the hosting account is not a vault.** D-08 says so itself: this reduces exposure
 *     and does not survive a full account compromise. The report can only assert what the filesystem
 *     shows.
 */
final class KeyCustody
{
    /**
     * @param string      $publicRoot      the web root; nothing secret may live under it
     * @param string      $backupDirectory where the archives are, which the key must not be in
     * @param string|null $envFile         the file that holds the key, or null when it is only in the
     *        process environment
     * @param string|null $recoveryPath    the independent recovery copy, from
     *        `BACKUP_RECOVERY_KEY_FILE`
     * @param bool        $keyConfigured   whether the key itself is set at all
     */
    public function __construct(
        private readonly string $publicRoot,
        private readonly string $backupDirectory,
        private readonly ?string $envFile,
        private readonly ?string $recoveryPath,
        private readonly bool $keyConfigured,
    ) {
    }

    /**
     * @return list<array{check: string, state: string, detail: string}>
     */
    public function checks(): array
    {
        return [
            $this->keyPresent(),
            $this->keyOutsideWebRoot(),
            $this->keyFilePermissions(),
            $this->keyNotBesideArchives(),
            $this->recoveryCopy(),
        ];
    }

    /** @return array{check: string, state: string, detail: string} */
    private function keyPresent(): array
    {
        if (!$this->keyConfigured) {
            return [
                'check' => 'Backup key',
                'state' => 'fail',
                'detail' => 'BACKUP_ENCRYPTION_KEY is not set. The hourly job runs with '
                    . '--require-encryption, so it will refuse to back up rather than write plaintext.',
            ];
        }

        return [
            'check' => 'Backup key',
            'state' => 'ok',
            'detail' => 'set (value not shown)',
        ];
    }

    /** @return array{check: string, state: string, detail: string} */
    private function keyOutsideWebRoot(): array
    {
        if ($this->envFile === null) {
            return [
                'check' => 'Outside the web root',
                'state' => 'warn',
                'detail' => 'the key is in the process environment; nothing on disk can be checked',
            ];
        }

        if ($this->isInside($this->envFile, $this->publicRoot)) {
            return [
                'check' => 'Outside the web root',
                'state' => 'fail',
                'detail' => $this->envFile . ' is inside the document root, so the key can be served as a file.',
            ];
        }

        return [
            'check' => 'Outside the web root',
            'state' => 'ok',
            'detail' => $this->envFile,
        ];
    }

    /** @return array{check: string, state: string, detail: string} */
    private function keyFilePermissions(): array
    {
        if ($this->envFile === null) {
            return [
                'check' => 'Key file mode',
                'state' => 'warn',
                'detail' => 'no key file to inspect',
            ];
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return [
                'check' => 'Key file mode',
                'state' => 'warn',
                'detail' => 'file modes are not ACLs on Windows; check the account permissions by hand',
            ];
        }

        if (!is_file($this->envFile)) {
            return [
                'check' => 'Key file mode',
                'state' => 'fail',
                'detail' => $this->envFile . ' does not exist',
            ];
        }

        $mode = fileperms($this->envFile);

        if ($mode === false) {
            return [
                'check' => 'Key file mode',
                'state' => 'warn',
                'detail' => 'file mode could not be read',
            ];
        }

        $permissions = $mode & 0777;

        // 0600 is the target. Anything with a group or other bit is a fail, because on shared
        // hosting "group" is not a small set of people.
        if (($permissions & 0077) !== 0) {
            return [
                'check' => 'Key file mode',
                'state' => 'fail',
                'detail' => sprintf(
                    '%s is %04o; D-08 requires 0600 so no other account on the host can read it.',
                    $this->envFile,
                    $permissions,
                ),
            ];
        }

        return [
            'check' => 'Key file mode',
            'state' => 'ok',
            'detail' => sprintf('%04o', $permissions),
        ];
    }

    /** @return array{check: string, state: string, detail: string} */
    private function keyNotBesideArchives(): array
    {
        if (!is_dir($this->backupDirectory)) {
            return [
                'check' => 'Key apart from archives',
                'state' => 'warn',
                'detail' => 'the backup directory does not exist yet, so there is nothing to be apart from',
            ];
        }

        // A key file named in the backup directory, whatever it is called, is one `tar` away from
        // being shipped with the data it protects.
        $suspicious = [];

        foreach (scandir($this->backupDirectory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $this->backupDirectory . DIRECTORY_SEPARATOR . $entry;

            if (is_file($path) && preg_match('/key/i', $entry) === 1) {
                $suspicious[] = $entry;
            }
        }

        if ($suspicious !== []) {
            return [
                'check' => 'Key apart from archives',
                'state' => 'fail',
                'detail' => 'possible key material beside the archives: ' . implode(', ', $suspicious),
            ];
        }

        return [
            'check' => 'Key apart from archives',
            'state' => 'ok',
            'detail' => 'no key-shaped file in ' . $this->backupDirectory,
        ];
    }

    /** @return array{check: string, state: string, detail: string} */
    private function recoveryCopy(): array
    {
        if ($this->recoveryPath === null) {
            return [
                'check' => 'Recovery copy',
                'state' => 'warn',
                'detail' => 'BACKUP_RECOVERY_KEY_FILE is not set. D-08 requires an independent copy in '
                    . 'a separate protected location; until one exists, losing this host loses every archive.',
            ];
        }

        if (!is_file($this->recoveryPath)) {
            return [
                'check' => 'Recovery copy',
                'state' => 'fail',
                'detail' => $this->recoveryPath . ' is not there',
            ];
        }

        if ($this->isInside($this->recoveryPath, $this->publicRoot)) {
            return [
                'check' => 'Recovery copy',
                'state' => 'fail',
                'detail' => 'the recovery copy is inside the document root, which makes it a second exposure.',
            ];
        }

        if ($this->isInside($this->recoveryPath, $this->backupDirectory)) {
            return [
                'check' => 'Recovery copy',
                'state' => 'fail',
                'detail' => 'the recovery copy is inside the backup directory, so it is not independent '
                    . 'of the archives it unlocks.',
            ];
        }

        if ($this->envFile !== null && $this->normalise($this->recoveryPath) === $this->normalise($this->envFile)) {
            return [
                'check' => 'Recovery copy',
                'state' => 'fail',
                'detail' => 'the recovery copy is the same file as the active key.',
            ];
        }

        return [
            'check' => 'Recovery copy',
            'state' => 'ok',
            'detail' => $this->recoveryPath,
        ];
    }

    public function hasFailure(): bool
    {
        foreach ($this->checks() as $check) {
            if ($check['state'] === 'fail') {
                return true;
            }
        }

        return false;
    }

    private function isInside(string $path, string $directory): bool
    {
        $path = $this->normalise($path);
        $directory = rtrim($this->normalise($directory), DIRECTORY_SEPARATOR);

        return $path === $directory || str_starts_with($path, $directory . DIRECTORY_SEPARATOR);
    }

    private function normalise(string $path): string
    {
        $real = realpath($path);

        return $real === false ? $path : $real;
    }
}
