<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A durable record of the last scheduled backup that did not happen.
 *
 * D-09 of HR-ENG-DEC-R1-003 requires a scheduled backup to fail closed and to "generate an actionable
 * operational alert when a scheduled backup fails". This project has no verified operations mailbox or
 * paging destination — D-06 forbids inventing one — so the ceiling here is an artefact an operator or a
 * monitoring check can read, next to the backups themselves:
 *
 *   - the failure is written where the backups live, so whoever looks for a backup finds the reason
 *     there is not a new one;
 *   - `diag` reports it as `fail` while it is newer than the newest successful backup, which is the
 *     condition that matters. A failure followed by a success is history, not an alert.
 *
 * What it is not: a notification. Nothing is sent anywhere, and saying otherwise would make a silent
 * failure look handled.
 *
 * One line of JSON per failure, append-only, because the question an operator asks later is "how often",
 * and a file that keeps only the last line cannot answer it.
 */
final class BackupAlert
{
    public const FILE = 'BACKUP-FAILED.log';

    /** A base64 secret is long, unbroken and keyword-free; anything of that shape is redacted on write. */
    private const SECRET_SHAPE = '/\A[A-Za-z0-9+\/_\-]{32,}={0,2}\z/';

    private const DETAIL_LIMIT = 400;

    public static function path(string $directory): string
    {
        return rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::FILE;
    }

    /**
     * Append one failure.
     *
     * `$reason` is a short stable token (`key-missing`, `dump-failed`) so a monitoring check can match
     * on it; `$detail` is what the operator needs to act, flattened to one line and stripped of
     * anything shaped like a secret before it is written.
     */
    public static function record(
        string $directory,
        string $reason,
        string $detail = '',
        ?DateTimeImmutable $now = null,
    ): void {
        $at = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');

        $line = json_encode(
            ['at' => $at, 'reason' => $reason, 'detail' => self::sanitise($detail)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($line === false) {
            return;
        }

        $path = self::path($directory);

        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            return;
        }

        // Best effort by design: failing to write the alert must not turn a failed backup into a
        // crash, which would lose the exit code the cron wrapper needs.
        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * The most recent failure, or null when none has been recorded.
     *
     * @return array{at: string, reason: string, detail: string}|null
     */
    public static function latest(string $directory): ?array
    {
        $path = self::path($directory);

        if (!is_file($path)) {
            return null;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return null;
        }

        $found = null;

        // Forward scan, last valid line wins. A truncated final line from an interrupted write is
        // skipped rather than treated as the newest failure.
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);

            if (!is_array($decoded)) {
                continue;
            }

            $at = $decoded['at'] ?? null;
            $reason = $decoded['reason'] ?? null;
            $detail = $decoded['detail'] ?? '';

            if (!is_string($at) || !is_string($reason)) {
                continue;
            }

            $found = ['at' => $at, 'reason' => $reason, 'detail' => is_string($detail) ? $detail : ''];
        }

        return $found;
    }

    /**
     * Is the recorded failure newer than the newest successful backup?
     *
     * A failure the next run made good is not an alert. A failure that the next run never followed with
     * a success is, and that is the only thing `diag` should call a failure.
     */
    public static function outranksSuccess(?string $alertAt, ?int $successModifiedAt): bool
    {
        if ($alertAt === null) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $alertAt, new DateTimeZone('UTC'));

        if ($parsed === false) {
            return false;
        }

        if ($successModifiedAt === null) {
            return true;
        }

        return $parsed->getTimestamp() > $successModifiedAt;
    }

    private static function sanitise(string $detail): string
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', $detail));

        // Two shapes, because a secret arrives in two: alone (a pasted key) or as the value of an
        // assignment (`SOMETHING_KEY=…`). The second is matched only after `=` or `:`, which keeps a
        // long file path readable — a path has slashes and dots where a credential has none.
        $flat = (string) preg_replace('/([=:]\s*)([A-Za-z0-9+\/_-]{24,}={0,2})/', '$1[redacted]', $flat);

        $words = [];

        foreach (explode(' ', $flat) as $word) {
            $words[] = preg_match(self::SECRET_SHAPE, trim($word, '",;()')) === 1 ? '[redacted]' : $word;
        }

        $clean = implode(' ', $words);

        return strlen($clean) > self::DETAIL_LIMIT ? substr($clean, 0, self::DETAIL_LIMIT) . '…' : $clean;
    }
}
