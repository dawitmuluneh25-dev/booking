<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How far the medication conversation got (MED-002).
 *
 * The package asks for "unknown" and "not reconciled" to be distinguishable, and
 * they are genuinely different failures:
 *
 *   - `unknown` means nobody asked, so there is no list at all;
 *   - `not_reconciled` means a list exists but no clinician has checked it — the
 *     usual state of a list typed in by a patient at intake.
 *
 * `none_reported_confirmed` is the only value that means the patient takes nothing,
 * and it can only be reached by asking. An empty list therefore never reads as
 * "takes no medication", which is §6.2's warning applied to the field where it does
 * the most damage: a patient on an anticoagulant who reports nothing because the
 * form did not ask.
 */
enum MedicationReviewStatus: string
{
    case Recorded = 'recorded';
    case NotReconciled = 'not_reconciled';
    case NoneReportedConfirmed = 'none_reported_confirmed';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => 'List recorded and reviewed',
            self::NotReconciled => 'Reported, not yet reconciled',
            self::NoneReportedConfirmed => 'None — confirmed by asking',
            self::Unknown => 'Unknown',
        };
    }

    /** Whether a list has to accompany this status. */
    public function requiresList(): bool
    {
        return $this === self::Recorded || $this === self::NotReconciled;
    }

    /** Whether a clinician has actually checked the list. */
    public function isReconciled(): bool
    {
        return $this === self::Recorded || $this === self::NoneReportedConfirmed;
    }
}
