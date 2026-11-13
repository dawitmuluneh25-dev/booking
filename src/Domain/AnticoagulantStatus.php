<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Whether the patient takes an anticoagulant or antiplatelet drug (MED-003).
 *
 * The four values are the approved vocabulary, and the two negatives are not the
 * same fact: `no_use_confirmed` is a statement somebody made after asking, while
 * `unknown` and `not_yet_assessed` describe how far the asking got. §6.2 forbids
 * collapsing either into "none", because a bleeding risk assessed from a blank
 * field is a bleeding risk nobody assessed.
 *
 * No value here carries an instruction. §6.5 is explicit that the system must not
 * issue stop/restart advice; the drug, indication and last dose are recorded, and a
 * clinician decides.
 */
enum AnticoagulantStatus: string
{
    case ReportedUse = 'reported_use';
    case NoUseConfirmed = 'no_use_confirmed';
    case Unknown = 'unknown';
    case NotYetAssessed = 'not_yet_assessed';

    public function label(): string
    {
        return match ($this) {
            self::ReportedUse => 'Reports taking one',
            self::NoUseConfirmed => 'None — confirmed by asking',
            self::Unknown => 'Unknown',
            self::NotYetAssessed => 'Not yet assessed',
        };
    }

    /** Whether this value asserts a finding that needs detail behind it. */
    public function assertsUse(): bool
    {
        return $this === self::ReportedUse;
    }

    /** Whether a clinician has actually established the answer. */
    public function isEstablished(): bool
    {
        return $this === self::ReportedUse || $this === self::NoUseConfirmed;
    }
}
