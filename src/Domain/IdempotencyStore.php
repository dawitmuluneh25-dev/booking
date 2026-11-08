<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Claim-then-complete record of work that must happen once.
 *
 * The claim is written and committed before the work starts, so a second request
 * arriving during the first one sees `conflict` rather than repeating it. The
 * record is marked complete with the reference of whatever was created.
 *
 * A claim that is never completed is a request that died half way; it expires
 * rather than being retried automatically, because the caller cannot tell from
 * the record alone whether the side effects happened.
 */
interface IdempotencyStore
{
    /**
     * @param string $scope the command, e.g. "referral.submit"
     * @param string $key the client's token, hashed by the implementation
     * @param string $fingerprint hash of the normalised request payload
     */
    public function claim(
        string $scope,
        string $key,
        string $fingerprint,
        Actor $actor,
        string $nowUtc,
    ): IdempotencyClaim;

    /** Record the result of a completed claim. */
    public function complete(string $scope, string $key, string $reference, string $nowUtc): void;
}
