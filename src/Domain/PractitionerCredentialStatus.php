<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The standing of a recorded credential.
 *
 * Three values, not five. `expired` is absent because expiry is a fact about a
 * date: a credential that ran out is still a verified credential whose window
 * closed, and storing that as a status would need something to write it — a job
 * that, until it ran, would leave the row saying `verified` while the calendar
 * said otherwise. `pending` is absent for the same kind of reason: recording the
 * row is the verification, so a credential nobody has verified has no row.
 */
enum PractitionerCredentialStatus: string
{
    case Verified = 'verified';
    case Suspended = 'suspended';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Verified => 'Verified',
            self::Suspended => 'Suspended',
            self::Revoked => 'Revoked',
        };
    }

    /** Whether a credential in this state can authorise anything on its own. */
    public function authorisesApproval(): bool
    {
        return $this === self::Verified;
    }

    /**
     * Revocation is final; suspension is not.
     *
     * A suspension is "stop until this is looked at again", so it can be lifted. A
     * revocation is a finding that the credential should not have been relied on,
     * and reinstating it in place would erase that finding. A practitioner whose
     * credential is revoked can be credentialed again — as a new record, with a new
     * verification and its own evidence.
     */
    public function isFinal(): bool
    {
        return $this === self::Revoked;
    }
}
