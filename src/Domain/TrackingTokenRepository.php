<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Storage for status-link credentials.
 *
 * Only the SHA-256 of a token is ever stored, so a database copy does not let
 * somebody open a patient's status page. `consume` must be a single conditional
 * update: that is what makes two simultaneous redemptions resolve to one winner
 * rather than two readers of the same secret.
 */
interface TrackingTokenRepository
{
    public function issue(
        int $referralId,
        string $tokenHash,
        string $expiresAtUtc,
        ?int $issuedByUserId,
        string $createdAtUtc,
    ): void;

    /**
     * Mark the token used and return the referral it pointed at.
     *
     * Null covers every refusal - unknown, already used, expired - because the
     * caller must answer them identically.
     */
    public function consume(string $tokenHash, string $occurredAtUtc): ?int;

    /** Status links die with the referral's identity at retention time. */
    public function purgeForReferral(int $referralId): void;
}
