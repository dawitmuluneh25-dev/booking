<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * A pre-procedure assessment cannot be recorded against a referral in this state.
 *
 * Its own exception rather than a borrow: saying "consent cannot be recorded" when
 * the subject is an assessment would send a reader looking in the wrong part of the
 * record, and the two refusals come from different rules.
 */
final class AssessmentNotPermittedException extends RuntimeException
{
    public function __construct(
        public readonly ReferralStatus $status,
        public readonly string $reason,
    ) {
        parent::__construct(
            sprintf('A referral that is %s cannot take a pre-procedure assessment. %s', $status->value, $reason),
        );
    }
}
