<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\RetentionSchedule;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * D-02's validation, as a record a machine can compare instead of a paragraph it must trust.
 *
 * The gate used to have two answers: `open` while the flag was off, `partial` while it was on. There
 * was no way to reach `met`, because the validation D-02 requires was prose in
 * `docs/operations/retention-validation.md` and nothing could tell whether it was still true. A gap
 * that cannot close is not a gate; it is a comment, and after eighteen months of reading "the
 * validation behind it is an attestation" nobody goes back to check whether the attestation still
 * describes the table.
 *
 * So the validation becomes a record with four fields — who, when, over what, and a fingerprint of the
 * schedule as it stood — and the gate compares the fingerprint with the live one. Three consequences
 * are the point of the design:
 *
 *   - **Silence is not a pass.** A missing file, a null approver and a blank date are all
 *     `unrecorded`; the gate says so and stays `partial`. Nothing defaults to validated.
 *   - **An amendment un-validates itself.** Change a period, add a class, or move the backup limits and
 *     the fingerprint changes, so the gate drops back to `partial` without anyone remembering to do it.
 *     That is the property the previous prose could not have.
 *   - **Nothing here can write the record.** There is no command that fills it in, because §3.3 says no
 *     item in this record authorises release by itself — and a program that could mark its own
 *     attestation current would be exactly that mechanism. The file is committed by a person who holds
 *     the approval, and the diff is the approval's provenance.
 *
 * What this class still cannot do, stated so it is not discovered later: it cannot verify that the
 * person named exists, that they hold the office, or that they read the table. It records an assertion
 * and compares its scope to the present. The signature stays a human act.
 */
final class RetentionValidationRecord
{
    /** No file, or a file that names nobody. The gate may not treat this as validated. */
    public const UNRECORDED = 'unrecorded';

    /** Somebody is named, but the record is not complete enough to say over what or when. */
    public const INCOMPLETE = 'incomplete';

    /** A complete record whose fingerprint no longer describes the schedule in this build. */
    public const STALE = 'stale';

    /** A complete record whose fingerprint matches the schedule exactly. */
    public const CURRENT = 'current';

    /** D-02 names the office; the record is not valid unless it claims that office. */
    public const OFFICE = 'Legal / Data Protection';

    private function __construct(
        public readonly string $state,
        public readonly ?string $approver,
        public readonly ?string $approvedOn,
        public readonly ?string $scope,
        public readonly ?string $fingerprint,
        public readonly string $explanation,
    ) {
    }

    /**
     * Read the register file, or say why there is nothing to read.
     *
     * A missing file is `unrecorded` rather than an exception: a checkout that has not yet been
     * validated is the normal state of a new deployment, and a command that could not report the gate
     * for want of a file would be worse than one that reports it honestly.
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return self::unrecorded('No validation record exists at ' . basename($path) . '.');
        }

        /** @var mixed $loaded */
        $loaded = require $path;

        if (!is_array($loaded)) {
            throw new InvalidArgumentException(
                $path . ' must return an array with approver, approved_on, scope and schedule_fingerprint.'
            );
        }

        // Keys are literal in every register this reads; the annotation states that rather than the loop
        // that would otherwise exist only to convince the analyser.
        /** @var array<string, mixed> $loaded */
        return self::fromArray($loaded);
    }

    /** @param array<string, mixed> $record */
    public static function fromArray(array $record): self
    {
        $approver = self::text($record['approver'] ?? null);
        $approvedOn = self::text($record['approved_on'] ?? null);
        $scope = self::text($record['scope'] ?? null);
        $fingerprint = self::text($record['schedule_fingerprint'] ?? null);
        $office = self::text($record['office'] ?? null);

        if ($approver === null) {
            return self::unrecorded('The validation record names no approver, so nothing has been asserted.');
        }

        if ($office !== self::OFFICE) {
            return new self(
                state: self::INCOMPLETE,
                approver: $approver,
                approvedOn: $approvedOn,
                scope: $scope,
                fingerprint: $fingerprint,
                explanation: 'The record is approved by "' . ($office ?? 'an unnamed office') . '"; D-02 requires '
                    . 'validation by ' . self::OFFICE . '.',
            );
        }

        if ($approvedOn === null || DateTimeImmutable::createFromFormat('!Y-m-d', $approvedOn) === false) {
            return new self(
                state: self::INCOMPLETE,
                approver: $approver,
                approvedOn: $approvedOn,
                scope: $scope,
                fingerprint: $fingerprint,
                explanation: 'The record names ' . $approver . ' but carries no approval date in YYYY-MM-DD form,'
                    . ' so there is no point in time to compare the schedule against.',
            );
        }

        if ($scope === null) {
            return new self(
                state: self::INCOMPLETE,
                approver: $approver,
                approvedOn: $approvedOn,
                scope: null,
                fingerprint: $fingerprint,
                explanation: 'The record names ' . $approver . ' on ' . $approvedOn . ' but does not say what was'
                    . ' validated; D-02 requires the periods, the lawful basis and the preservation exceptions.',
            );
        }

        $current = RetentionSchedule::fingerprint();

        if ($fingerprint === null) {
            return new self(
                state: self::STALE,
                approver: $approver,
                approvedOn: $approvedOn,
                scope: $scope,
                fingerprint: null,
                explanation: 'The record carries no schedule fingerprint, so it cannot be shown to describe the'
                    . ' table this build executes. Current fingerprint: ' . RetentionSchedule::fingerprintShort() . '.',
            );
        }

        if (!hash_equals($current, $fingerprint)) {
            return new self(
                state: self::STALE,
                approver: $approver,
                approvedOn: $approvedOn,
                scope: $scope,
                fingerprint: $fingerprint,
                explanation: 'The schedule changed after ' . $approvedOn . ': the record covers '
                    . substr($fingerprint, 0, 12) . ', this build is ' . RetentionSchedule::fingerprintShort()
                    . '. Re-validation is required for the periods now adopted.',
            );
        }

        return new self(
            state: self::CURRENT,
            approver: $approver,
            approvedOn: $approvedOn,
            scope: $scope,
            fingerprint: $fingerprint,
            explanation: 'Validated by ' . $approver . ' (' . self::OFFICE . ') on ' . $approvedOn
                . ', covering the schedule this build executes (fingerprint ' . RetentionSchedule::fingerprintShort()
                . ').',
        );
    }

    public static function unrecorded(string $because): self
    {
        return new self(
            state: self::UNRECORDED,
            approver: null,
            approvedOn: null,
            scope: null,
            fingerprint: null,
            explanation: $because,
        );
    }

    public function isCurrent(): bool
    {
        return $this->state === self::CURRENT;
    }

    /** One line for a report: which state, and why. */
    public function summary(): string
    {
        return '[' . strtoupper($this->state) . '] ' . $this->explanation;
    }

    /**
     * A file path is a test double's convenience, so `null` from a value in the register is not a path.
     * Blank strings are null too: an empty approver is an unattested one, not a person called "".
     */
    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
