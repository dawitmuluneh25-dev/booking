<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * Consent cannot be recorded, or this record cannot be changed.
 *
 * Separate from `ReferralTransitionException` because nothing transitions here: the
 * referral stays exactly where it is and no status is written. What the caller got
 * wrong is the timing of a different record — consent belongs after the clinical
 * decision and before the procedure (§18.1) — or the state of the record itself.
 *
 * `$status` is null when the refusal is about the consent row rather than about the
 * referral, so a reader is never shown a referral state that had nothing to do with
 * the refusal.
 */
final class ConsentNotPermittedException extends RuntimeException
{
    public function __construct(
        public readonly ?ReferralStatus $status,
        public readonly string $reason,
    ) {
        parent::__construct($status === null
            ? $reason
            : sprintf('A referral that is %s cannot record consent. %s', $status->value, $reason));
    }
}
