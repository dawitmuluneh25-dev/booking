<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The per-network cap on a public form.
 *
 * Five submissions an hour and twenty a day, per hashed address. Two limits rather
 * than one because they answer different abuse: a script hammering the form is
 * stopped by the hourly cap, while a slow drip over a week is stopped by the daily
 * one. Neither limit protects mail — no mail is queued on submission at all — so
 * what they protect is the queue and the table it lives in.
 *
 * A refused attempt is **not** recorded. Recording refusals would let one blocked
 * caller keep extending their own lockout, and would turn a shared clinic network
 * into a permanent block after a single busy morning.
 */
final class AccountRequestThrottle
{
    public const PER_HOUR = 5;

    public const PER_DAY = 20;

    public function __construct(private readonly AccountRequestAttempts $attempts)
    {
    }

    /** @return bool true when the caller may proceed; the attempt is recorded then */
    public function admit(string $ipHash, string $nowUtc): bool
    {
        $now = new DateTimeImmutable($nowUtc, new DateTimeZone('UTC'));

        $hourAgo = $now->modify('-1 hour')->format('Y-m-d H:i:s');
        $dayAgo = $now->modify('-1 day')->format('Y-m-d H:i:s');

        if ($this->attempts->countSince($ipHash, $hourAgo) >= self::PER_HOUR) {
            return false;
        }

        if ($this->attempts->countSince($ipHash, $dayAgo) >= self::PER_DAY) {
            return false;
        }

        $this->attempts->record($ipHash, $nowUtc);

        return true;
    }

    /**
     * Addresses are stored as a keyed hash. A plain sha256 of an IPv4 address is
     * reversible by enumerating 2^32 values in seconds, which would make the
     * "hash" a slower way of storing the address.
     */
    public static function hashAddress(string $address, string $key): string
    {
        return hash_hmac('sha256', $address, $key);
    }
}
