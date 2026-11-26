<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * HR-ENG-DEC-R1-003 D-10's credential register, and the arithmetic that makes it a gate.
 *
 * D-10 defers routine rotation as a *blocker* and then requires three things anyway: a named owner for
 * every secret, immediate rotation on any of five named events, and "a maximum 90-day rotation interval
 * for hosting, database, SMTP, and API credentials" in production. A policy that exists only in a
 * decision record is not a control; what this class adds is the part a machine can hold you to.
 *
 * Three properties are deliberate:
 *
 *   - **It never reads a value.** Every question is about the *register* — is there an entry, an owner,
 *     a rotation date — never about the secret. A report that echoed a credential would be the leak it
 *     was written to prevent, so `configured` is a boolean and nothing more.
 *   - **`null` is not "compliant".** A credential with no rotation on record is `unrecorded`, not `ok`.
 *     Every credential in this system is currently unrecorded, and the honest reading of that is a gap
 *     to close, not a green row.
 *   - **The backup key is not on the calendar.** D-10's archive rule outranks the 90 days: a key that
 *     encrypts archives still within retention may not be destroyed to satisfy an interval. The entry
 *     carries a null interval and the archive question is answered separately, from the filenames.
 *
 * What it cannot do is stated here rather than discovered in an audit: it cannot see whether a panel
 * password was changed, whether a value was reused from another system, or whether a custodian exists.
 * Those are attestations. The register records the field, and leaves it empty when nobody has attested.
 */
final class CredentialInventory
{
    /** D-10: "a maximum 90-day rotation interval for hosting, database, SMTP, and API credentials". */
    public const ROTATION_INTERVAL_DAYS = 90;

    /**
     * How close to the due date counts as a warning.
     *
     * A fortnight, because the rotation itself is an act by a person on a hosting panel and the last
     * week is when that act collides with everything else. A warning that arrives on the due date is a
     * warning that arrives when there is nothing left to do with it.
     */
    public const DUE_SOON_DAYS = 14;

    /** @var list<string> */
    public const SOURCES = ['env', 'external'];

    /** @var list<string> */
    public const KINDS = ['application', 'api', 'backup-key', 'database', 'hosting'];

    /**
     * @var list<array{
     *     key: string,
     *     source: string,
     *     kind: string,
     *     function: string,
     *     office: string,
     *     custodian: ?string,
     *     interval_days: ?int,
     *     rotated_on: ?string,
     *     rotation_requested_on: ?string
     * }>
     */
    private array $credentials = [];

    /** @var array<string, string> */
    private array $nonSecret = [];

    /**
     * @param list<array<string, mixed>> $credentials the register, normalised and validated here
     * @param array<string, string>      $nonSecret   every other environment key, with the reason it is
     *                                                not a secret
     */
    public function __construct(array $credentials, array $nonSecret)
    {
        $seen = [];

        foreach ($credentials as $entry) {
            $normalised = self::normalise($entry);
            $this->assertEntryIsWellFormed($normalised);

            if (array_key_exists($normalised['key'], $seen)) {
                throw new InvalidArgumentException(
                    'The credential register lists "' . $normalised['key'] . '" twice. One secret, one row.'
                );
            }

            $seen[$normalised['key']] = true;

            if ($normalised['source'] === 'env' && array_key_exists($normalised['key'], $nonSecret)) {
                throw new InvalidArgumentException(
                    '"' . $normalised['key'] . '" is registered as a credential and as a non-secret.'
                    . ' It cannot be both.'
                );
            }

            $this->credentials[] = $normalised;
        }

        foreach ($nonSecret as $key => $reason) {
            if (trim($key) === '' || trim($reason) === '') {
                throw new InvalidArgumentException(
                    'Every non-secret entry needs a key and a reason; one of them is blank.'
                );
            }
        }

        $this->nonSecret = $nonSecret;
    }

    /**
     * Read the register from disk.
     *
     * @throws InvalidArgumentException the file is missing, does not return the documented shape, or
     *                                  carries an entry that fails validation
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException('Credential register not found: ' . $path);
        }

        /** @var mixed $loaded */
        $loaded = require $path;

        if (!is_array($loaded) || !isset($loaded['credentials'], $loaded['non_secret'])) {
            throw new InvalidArgumentException(
                $path . ' must return [\'credentials\' => [...], \'non_secret\' => [...]]'
            );
        }

        $credentials = $loaded['credentials'];
        $nonSecret = $loaded['non_secret'];

        if (!is_array($credentials) || !is_array($nonSecret)) {
            throw new InvalidArgumentException($path . ' must return arrays for both sections.');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values($credentials);

        /** @var array<string, string> $reasons */
        $reasons = [];

        foreach ($nonSecret as $key => $reason) {
            if (!is_string($key) || !is_string($reason)) {
                throw new InvalidArgumentException('Every non-secret entry must be a string key and reason.');
            }

            $reasons[$key] = $reason;
        }

        return new self($rows, $reasons);
    }

    /**
     * The register, in file order.
     *
     * @return list<array{
     *     key: string,
     *     source: string,
     *     kind: string,
     *     function: string,
     *     office: string,
     *     custodian: ?string,
     *     interval_days: ?int,
     *     rotated_on: ?string,
     *     rotation_requested_on: ?string
     * }>
     */
    public function credentials(): array
    {
        return $this->credentials;
    }

    /** @return array<string, string> */
    public function nonSecret(): array
    {
        return $this->nonSecret;
    }

    /**
     * Every environment key the register accounts for, secret or not.
     *
     * This is the other half of the drift guard: the test compares it against the keys the code reads.
     *
     * @return list<string>
     */
    public function classifiedEnvKeys(): array
    {
        $keys = array_keys($this->nonSecret);

        foreach ($this->credentials as $entry) {
            if ($entry['source'] === 'env') {
                $keys[] = $entry['key'];
            }
        }

        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    public function secretEnvKeys(): array
    {
        $keys = [];

        foreach ($this->credentials as $entry) {
            if ($entry['source'] === 'env') {
                $keys[] = $entry['key'];
            }
        }

        sort($keys);

        return $keys;
    }

    /**
     * One row per credential: what state it is in, and the sentence that explains it.
     *
     * `$configured` is the set of environment keys that currently hold a value. Passing it in rather
     * than reading the environment keeps the report testable and keeps every secret value out of this
     * class — it is only ever a membership test.
     *
     * @param list<string> $configured
     *
     * @return list<array{
     *     key: string,
     *     kind: string,
     *     office: string,
     *     custodian: ?string,
     *     state: string,
     *     detail: string,
     *     due_on: ?string,
     *     days_remaining: ?int
     * }>
     */
    public function report(string $today, array $configured): array
    {
        $now = self::today($today);
        $rows = [];

        foreach ($this->credentials as $entry) {
            $rows[] = $this->reportOne($entry, $now, $configured);
        }

        return $rows;
    }

    /**
     * Whether the report contains something that should stop a release.
     *
     * Overdue is always a failure. `unrecorded` and a blank custodian become failures only when
     * `$requireRecorded` is set, because on a development machine they are the true state of a register
     * that has never been through a rotation — a command that failed every time would be a command
     * nobody reads, which is how the last two control gaps in this project survived as long as they did.
     *
     * @param list<array{state: string, custodian: ?string}> $report
     */
    public function hasFailure(array $report, bool $requireRecorded): bool
    {
        foreach ($report as $row) {
            if ($row['state'] === 'overdue' || $row['state'] === 'invalid') {
                return true;
            }

            if ($requireRecorded && ($row['state'] === 'unrecorded' || $row['custodian'] === null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Archives that still depend on the previous backup key.
     *
     * D-10: "Old keys must not be destroyed until the corresponding backup archives have expired or
     * have been securely re-encrypted and verified." Archive names carry a UTC timestamp
     * (`booking-YYYYMMDD-HHMMSS.sql.gz[.enc]`), so the question "which archives predate the rotation" is
     * answerable from the directory listing and nothing else. Anything whose timestamp falls before the
     * rotation date was written under the old key.
     *
     * @param list<string> $archiveNames
     *
     * @return list<string> the names that predate the rotation, oldest first
     */
    public static function archivesPredating(array $archiveNames, string $rotatedOn): array
    {
        $cutoff = self::today($rotatedOn)->format('Ymd');
        $predating = [];

        foreach ($archiveNames as $name) {
            if (preg_match('/\Abooking-(\d{8})-\d{6}(?:-\d+)?\.sql\.gz(?:\.enc)?\z/', $name, $matches) !== 1) {
                continue;
            }

            if ($matches[1] < $cutoff) {
                $predating[] = $name;
            }
        }

        sort($predating);

        return $predating;
    }

    /**
     * @param array{
     *     key: string,
     *     source: string,
     *     kind: string,
     *     function: string,
     *     office: string,
     *     custodian: ?string,
     *     interval_days: ?int,
     *     rotated_on: ?string,
     *     rotation_requested_on: ?string
     * } $entry
     * @param list<string> $configured
     *
     * @return array{
     *     key: string,
     *     kind: string,
     *     office: string,
     *     custodian: ?string,
     *     state: string,
     *     detail: string,
     *     due_on: ?string,
     *     days_remaining: ?int
     * }
     */
    private function reportOne(array $entry, DateTimeImmutable $now, array $configured): array
    {
        $base = [
            'key' => $entry['key'],
            'kind' => $entry['kind'],
            'office' => $entry['office'],
            'custodian' => $entry['custodian'],
            'due_on' => null,
            'days_remaining' => null,
        ];

        if ($entry['source'] === 'external') {
            // The rotation of an external secret cannot be performed or observed from this host, so the
            // only thing this register can hold is the request: when it was asked for, and by when it
            // must happen. Without the date the request is a sentence in a chat log, which is how a
            // panel password stays unchanged for a year while everyone believes it was raised.
            if ($entry['rotation_requested_on'] !== null) {
                return $base + [
                    'state' => 'unobservable',
                    'detail' => 'held outside this host; rotation requested '
                        . $entry['rotation_requested_on'] . ', not observable from here',
                ];
            }

            return $base + [
                'state' => 'unobservable',
                'detail' => 'held outside this host; presence and rotation cannot be read from here',
            ];
        }

        $configuredNow = in_array($entry['key'], $configured, true);

        if ($entry['rotated_on'] === null) {
            // The interval still applies, so an unset optional credential and an unrecorded one are
            // different problems: the first may be configuration, the second is always provenance.
            return $base + [
                'state' => 'unrecorded',
                'detail' => $configuredNow
                    ? 'set, but no rotation is on record; D-10 asks for a date, not an assurance'
                    : 'not set here, and no rotation is on record',
            ];
        }

        $rotated = self::today($entry['rotated_on']);

        if ($rotated > $now) {
            return $base + [
                'state' => 'invalid',
                'detail' => 'rotated_on is in the future (' . $entry['rotated_on'] . '); the register is wrong',
            ];
        }

        if ($entry['interval_days'] === null) {
            return $base + [
                'state' => 'ok',
                'detail' => 'rotated ' . $entry['rotated_on']
                    . '; governed by the archive rule rather than by an interval',
            ];
        }

        $due = $rotated->modify('+' . $entry['interval_days'] . ' days');
        $remaining = (int) $now->diff($due)->format('%r%a');

        if ($remaining < 0) {
            return [
                'key' => $entry['key'],
                'kind' => $entry['kind'],
                'office' => $entry['office'],
                'custodian' => $entry['custodian'],
                'state' => 'overdue',
                'detail' => sprintf(
                    'rotated %s, due %s: %d day(s) overdue',
                    $entry['rotated_on'],
                    $due->format('Y-m-d'),
                    abs($remaining),
                ),
                'due_on' => $due->format('Y-m-d'),
                'days_remaining' => $remaining,
            ];
        }

        $state = $remaining <= self::DUE_SOON_DAYS ? 'due' : 'ok';

        return [
            'key' => $entry['key'],
            'kind' => $entry['kind'],
            'office' => $entry['office'],
            'custodian' => $entry['custodian'],
            'state' => $state,
            'detail' => sprintf(
                'rotated %s, due %s (%d day(s))',
                $entry['rotated_on'],
                $due->format('Y-m-d'),
                $remaining,
            ),
            'due_on' => $due->format('Y-m-d'),
            'days_remaining' => $remaining,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array{
     *     key: string,
     *     source: string,
     *     kind: string,
     *     function: string,
     *     office: string,
     *     custodian: ?string,
     *     interval_days: ?int,
     *     rotated_on: ?string,
     *     rotation_requested_on: ?string
     * }
     */
    private static function normalise(array $entry): array
    {
        $key = $entry['key'] ?? null;
        $source = $entry['source'] ?? null;
        $kind = $entry['kind'] ?? null;
        $function = $entry['function'] ?? null;
        $office = $entry['office'] ?? null;
        $custodian = $entry['custodian'] ?? null;
        $interval = $entry['interval_days'] ?? null;
        $rotatedOn = $entry['rotated_on'] ?? null;
        $requestedOn = $entry['rotation_requested_on'] ?? null;

        return [
            'key' => is_string($key) ? $key : '',
            'source' => is_string($source) ? $source : '',
            'kind' => is_string($kind) ? $kind : '',
            'function' => is_string($function) ? $function : '',
            'office' => is_string($office) ? $office : '',
            'custodian' => is_string($custodian) ? $custodian : null,
            'interval_days' => is_int($interval) ? $interval : null,
            'rotated_on' => is_string($rotatedOn) ? $rotatedOn : null,
            'rotation_requested_on' => is_string($requestedOn) ? $requestedOn : null,
        ];
    }

    /**
     * @param array{
     *     key: string,
     *     source: string,
     *     kind: string,
     *     function: string,
     *     office: string,
     *     custodian: ?string,
     *     interval_days: ?int,
     *     rotated_on: ?string,
     *     rotation_requested_on: ?string
     * } $entry
     */
    private function assertEntryIsWellFormed(array $entry): void
    {
        if (preg_match('/\A[A-Za-z0-9.@_-]+\z/', $entry['key']) !== 1) {
            throw new InvalidArgumentException(
                'A credential key is missing or malformed: "' . $entry['key'] . '".'
            );
        }

        if (!in_array($entry['source'], self::SOURCES, true)) {
            throw new InvalidArgumentException(
                $entry['key'] . ': source must be one of ' . implode(', ', self::SOURCES) . '.'
            );
        }

        if (!in_array($entry['kind'], self::KINDS, true)) {
            throw new InvalidArgumentException(
                $entry['key'] . ': kind must be one of ' . implode(', ', self::KINDS) . '.'
            );
        }

        if (trim($entry['function']) === '') {
            throw new InvalidArgumentException($entry['key'] . ': the function it performs is not stated.');
        }

        if (trim($entry['office']) === '') {
            throw new InvalidArgumentException(
                $entry['key'] . ': no accountable office. D-10 requires a named owner for every secret.'
            );
        }

        if ($entry['custodian'] !== null && trim($entry['custodian']) === '') {
            throw new InvalidArgumentException(
                $entry['key'] . ': custodian is blank. Use null when nobody has been named.'
            );
        }

        if ($entry['interval_days'] !== null && $entry['interval_days'] < 1) {
            throw new InvalidArgumentException(
                $entry['key'] . ': an interval of ' . $entry['interval_days'] . ' days cannot be met.'
            );
        }

        if ($entry['rotated_on'] !== null) {
            self::today($entry['rotated_on']);
        }

        // A request is a date, not a promise: the same parse, so an impossible one is refused here
        // rather than printed as though it meant something.
        if ($entry['rotation_requested_on'] !== null) {
            self::today($entry['rotation_requested_on']);
        }
    }

    /**
     * Parse a `Y-m-d` string, refusing a date that does not exist.
     *
     * `createFromFormat` alone accepts 2026-02-30 and rolls it into March; the round-trip check is what
     * turns that into an error. A register with an impossible date is a register nobody can compute an
     * interval from.
     */
    private static function today(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('"' . $value . '" is not a date in Y-m-d form.');
        }

        return $date;
    }
}
