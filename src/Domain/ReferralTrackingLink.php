<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A status link the clinic can send over SMS or email.
 *
 * The link carries one opaque token and nothing else: no reference, no name, no
 * patient id. That is what "PHI-free URL" means here - the URL alone tells a
 * passer-by nothing, and the token is worthless once it is redeemed or expires.
 *
 * Three properties, and why each one:
 *
 * - **hashed at rest** - the database holds SHA-256 of the token, so a stolen
 *   backup cannot be replayed against the public route;
 * - **single use** - redemption is one conditional UPDATE; a forwarded link does
 *   not keep working;
 * - **expiring** - the lifetime is configured, not hard-coded, because how long
 *   a link should survive is a policy choice, and the default below is a security
 *   default rather than a clinical record-retention decision.
 */
final class ReferralTrackingLink
{
    /** 24 hours: long enough to open the link at home, short enough to matter. */
    public const DEFAULT_TTL_MINUTES = 1440;

    private const TOKEN_PATTERN = '/\A[a-f0-9]{64}\z/';

    private readonly int $ttlMinutes;

    public function __construct(
        private readonly TrackingTokenRepository $tokens,
        private readonly ReferralRepository $referrals,
        int $ttlMinutes = self::DEFAULT_TTL_MINUTES,
    ) {
        if ($ttlMinutes < 1) {
            throw new InvalidArgumentException('A tracking link must live for at least one minute.');
        }

        $this->ttlMinutes = $ttlMinutes;
    }

    /**
     * Mint a link for a referral and return the raw token, once.
     *
     * There is no way to ask for it again: only the hash is stored, which is why
     * the caller has to deliver it in the same breath.
     */
    public function issue(User $issuer, Referral $referral, string $issuedAtUtc): string
    {
        if (!$issuer->can(Permission::ReferralTriage)) {
            throw new AuthorisationException($issuer->role, Permission::ReferralTriage);
        }

        if ($referral->status->isTerminal()) {
            throw new ValidationException([
                'referral' => 'This referral is closed; a status link would show nothing useful.',
            ]);
        }

        $issued = new DateTimeImmutable($issuedAtUtc, new DateTimeZone('UTC'));
        $token = bin2hex(random_bytes(32));

        $this->tokens->issue(
            $referral->id,
            self::hash($token),
            $issued->modify('+' . $this->ttlMinutes . ' minutes')->format('Y-m-d H:i:s'),
            $issuer->id,
            $issued->format('Y-m-d H:i:s'),
        );

        return $token;
    }

    /**
     * Exchange a token for the referral it points at, or null.
     *
     * Every refusal - malformed, unknown, used, expired - returns null. A caller
     * that distinguished them would turn the public route into an oracle for
     * guessing valid tokens and for learning whether a link had been opened.
     */
    public function redeem(string $token, string $occurredAtUtc): ?Referral
    {
        $token = trim($token);

        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        $referralId = $this->tokens->consume(self::hash($token), $occurredAtUtc);

        return $referralId === null ? null : $this->referrals->findById($referralId);
    }

    public function ttlMinutes(): int
    {
        return $this->ttlMinutes;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
