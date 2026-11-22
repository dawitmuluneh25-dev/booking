<?php

declare(strict_types=1);

namespace App\Support;

use App\Database\DatabaseBackup;
use App\Database\Migrator;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

/**
 * Operational health beyond "is the database reachable".
 *
 * Every check answers one question an operator would ask at 8am: is the schema
 * current, is the outbox draining, is there a recent backup, is the disk
 * filling, are errors accumulating. States are `ok`, `warn` and `fail` so the
 * view can be scanned rather than read.
 */
final class Diagnostics
{
    private const WARN_DISK_BYTES = 256 * 1024 * 1024;
    private const FAIL_DISK_BYTES = 64 * 1024 * 1024;
    private const WARN_BACKUP_HOURS = 48;
    private const FAIL_BACKUP_HOURS = 24 * 7;
    private const WARN_OUTBOX_MINUTES = 15;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Migrator $migrator,
        private readonly DatabaseBackup $backups,
        private readonly LogReader $logs,
        private readonly string $root,
    ) {
    }

    /** @return list<array{label: string, value: string, state: string}> */
    public function checks(): array
    {
        return [
            $this->migrationCheck(),
            $this->outboxCheck(),
            $this->backupCheck(),
            $this->diskCheck(),
            $this->logCheck(),
            $this->dataCheck(),
        ];
    }

    /** @return array{pending: int, sending: int, sent: int, failed: int, dead: int, oldest_minutes: int|null} */
    public function outbox(): array
    {
        $counts = ['pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'dead' => 0];

        $statement = $this->pdo->query(
            'SELECT status, COUNT(*) AS total FROM notification_outbox GROUP BY status'
        );

        if ($statement !== false) {
            foreach ($statement->fetchAll() as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $status = $row['status'] ?? '';
                if (is_string($status) && array_key_exists($status, $counts)) {
                    $counts[$status] = self::integer($row, 'total');
                }
            }
        }

        $oldest = $this->pdo->query(
            "SELECT MIN(available_at) FROM notification_outbox WHERE status IN ('pending', 'failed')"
        );
        $oldestMinutes = null;

        if ($oldest !== false) {
            $value = $oldest->fetchColumn();
            if (is_string($value) && $value !== '') {
                $at = new DateTimeImmutable($value, new DateTimeZone('UTC'));
                $oldestMinutes = max(0, intdiv(time() - $at->getTimestamp(), 60));
            }
        }

        return [
            'pending' => $counts['pending'],
            'sending' => $counts['sending'],
            'sent' => $counts['sent'],
            'failed' => $counts['failed'],
            'dead' => $counts['dead'],
            'oldest_minutes' => $oldestMinutes,
        ];
    }

    /** @return array<string, int> status => count */
    public function bookingCounts(): array
    {
        $counts = [];

        $statement = $this->pdo->query('SELECT status, COUNT(*) AS total FROM bookings GROUP BY status');
        if ($statement === false) {
            return $counts;
        }

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = $row['status'] ?? '';
            if (is_string($status)) {
                $counts[$status] = self::integer($row, 'total');
            }
        }

        return $counts;
    }

    /** @return list<BackupFile> newest first */
    public function backups(int $limit = 10): array
    {
        return $this->backups->all($limit);
    }

    /** @return list<LogRecord> newest first */
    public function recentErrors(int $limit = 20): array
    {
        return $this->logs->recent('error', $limit);
    }

    public static function outboxState(int $pending, int $dead, ?int $oldestMinutes): string
    {
        if ($dead > 0) {
            return 'fail';
        }

        if ($pending > 0 && $oldestMinutes !== null && $oldestMinutes >= self::WARN_OUTBOX_MINUTES) {
            return 'warn';
        }

        return 'ok';
    }

    public static function backupState(?int $ageSeconds): string
    {
        if ($ageSeconds === null) {
            return 'fail';
        }

        if ($ageSeconds >= self::FAIL_BACKUP_HOURS * 3600) {
            return 'fail';
        }

        if ($ageSeconds >= self::WARN_BACKUP_HOURS * 3600) {
            return 'warn';
        }

        return 'ok';
    }

    public static function diskState(int $freeBytes): string
    {
        if ($freeBytes < self::FAIL_DISK_BYTES) {
            return 'fail';
        }

        if ($freeBytes < self::WARN_DISK_BYTES) {
            return 'warn';
        }

        return 'ok';
    }

    public static function logState(int $errors): string
    {
        return $errors > 0 ? 'warn' : 'ok';
    }

    public static function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KiB';
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MiB';
        }

        return round($bytes / (1024 * 1024 * 1024), 2) . ' GiB';
    }

    /** @return array{label: string, value: string, state: string} */
    private function migrationCheck(): array
    {
        try {
            $applied = 0;
            $pending = 0;
            $drift = 0;

            foreach ($this->migrator->status() as $migration) {
                if ($migration['applied']) {
                    $applied++;
                    if (!$migration['checksum_ok']) {
                        $drift++;
                    }
                    continue;
                }
                $pending++;
            }

            $value = $applied . ' applied';
            if ($pending > 0) {
                $value .= ', ' . $pending . ' pending';
            }
            if ($drift > 0) {
                $value .= ', ' . $drift . ' checksum drift';
            }

            $state = $drift > 0 ? 'fail' : ($pending > 0 ? 'warn' : 'ok');

            return ['label' => 'Migrations', 'value' => $value, 'state' => $state];
        } catch (Throwable) {
            return ['label' => 'Migrations', 'value' => 'unavailable', 'state' => 'fail'];
        }
    }

    /** @return array{label: string, value: string, state: string} */
    private function outboxCheck(): array
    {
        try {
            $outbox = $this->outbox();

            $value = sprintf(
                '%d pending, %d sent, %d dead',
                $outbox['pending'],
                $outbox['sent'],
                $outbox['dead'],
            );

            if ($outbox['oldest_minutes'] !== null) {
                $value .= ' · oldest ' . $outbox['oldest_minutes'] . ' min';
            }

            return [
                'label' => 'Notification outbox',
                'value' => $value,
                'state' => self::outboxState($outbox['pending'], $outbox['dead'], $outbox['oldest_minutes']),
            ];
        } catch (PDOException) {
            return ['label' => 'Notification outbox', 'value' => 'unavailable', 'state' => 'fail'];
        }
    }

    /**
     * The newest archive, and whether a recorded failure is more recent than it.
     *
     * An age threshold alone cannot report a failed run: at an hourly cadence a job that has been
     * failing since this morning still has a backup from this morning, and the age check would say
     * `ok` for two more days. D-09 asks for the failure itself to be visible, so the failure record
     * from `BackupAlert` is compared to the newest successful archive and wins when it is newer.
     *
     * @return array{label: string, value: string, state: string}
     */
    private function backupCheck(): array
    {
        $latest = $this->backups->latest();
        $alert = BackupAlert::latest($this->backups->directory());

        if ($latest === null) {
            if ($alert !== null) {
                return [
                    'label' => 'Latest backup',
                    'value' => 'never — last attempt failed: ' . $alert['reason'],
                    'state' => 'fail',
                ];
            }

            return ['label' => 'Latest backup', 'value' => 'never', 'state' => 'fail'];
        }

        $age = $latest->ageSeconds();

        if (BackupAlert::outranksSuccess($alert['at'] ?? null, $latest->modifiedAt)) {
            return [
                'label' => 'Latest backup',
                'value' => sprintf(
                    '%s, %s old — a later attempt failed: %s (%s)',
                    $latest->name,
                    self::duration($age),
                    $alert['reason'] ?? 'unknown',
                    $alert['at'] ?? 'no time',
                ),
                'state' => 'fail',
            ];
        }

        return [
            'label' => 'Latest backup',
            'value' => sprintf('%s, %s old (%s)', $latest->name, self::duration($age), $latest->humanSize()),
            'state' => self::backupState($age),
        ];
    }

    /** @return array{label: string, value: string, state: string} */
    private function diskCheck(): array
    {
        $free = disk_free_space($this->root . DIRECTORY_SEPARATOR . 'var');

        if ($free === false) {
            return ['label' => 'Disk free', 'value' => 'unknown', 'state' => 'warn'];
        }

        $freeBytes = (int) $free;

        return [
            'label' => 'Disk free',
            'value' => self::humanBytes($freeBytes),
            'state' => self::diskState($freeBytes),
        ];
    }

    /** @return array{label: string, value: string, state: string} */
    private function logCheck(): array
    {
        try {
            $counts = $this->logs->counts(24);
            $errors = ($counts['error'] ?? 0) + ($counts['critical'] ?? 0);

            return [
                'label' => 'Errors (24 h)',
                'value' => $errors . ' · ' . self::humanBytes($this->logs->totalBytes()) . ' of logs',
                'state' => self::logState($errors),
            ];
        } catch (Throwable) {
            return ['label' => 'Errors (24 h)', 'value' => 'unavailable', 'state' => 'warn'];
        }
    }

    /** @return array{label: string, value: string, state: string} */
    private function dataCheck(): array
    {
        try {
            $bookings = $this->bookingCounts();
            $total = array_sum($bookings);

            return [
                'label' => 'Bookings',
                'value' => sprintf(
                    '%d total · %d requested · %d confirmed',
                    $total,
                    $bookings['requested'] ?? 0,
                    $bookings['confirmed'] ?? 0,
                ),
                'state' => 'ok',
            ];
        } catch (PDOException) {
            return ['label' => 'Bookings', 'value' => 'unavailable', 'state' => 'fail'];
        }
    }

    /** @param array<mixed, mixed>|array<string, mixed> $row */
    private static function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return match (true) {
            is_int($value) => $value,
            is_string($value), is_float($value) => (int) $value,
            default => 0,
        };
    }

    private static function duration(int $seconds): string
    {
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' min';
        }

        if ($seconds < 86400) {
            return round($seconds / 3600, 1) . ' h';
        }

        return round($seconds / 86400, 1) . ' d';
    }
}
