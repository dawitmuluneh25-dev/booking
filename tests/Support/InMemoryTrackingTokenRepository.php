<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\TrackingTokenRepository;

/**
 * Status-link storage with the same single-use semantics as the database.
 *
 * Consumption is one operation that checks `used_at` and `expires_at` and stamps
 * the row, so the second attempt - and every attempt after expiry - returns null
 * for the same reason production would.
 */
final class InMemoryTrackingTokenRepository implements TrackingTokenRepository
{
    /** @var array<string, array{referral_id: int, expires_at: string, used_at: ?string}> */
    private array $tokens = [];

    public function issue(
        int $referralId,
        string $tokenHash,
        string $expiresAtUtc,
        ?int $issuedByUserId,
        string $createdAtUtc,
    ): void {
        $this->tokens[$tokenHash] = [
            'referral_id' => $referralId,
            'expires_at' => $expiresAtUtc,
            'used_at' => null,
        ];
    }

    public function consume(string $tokenHash, string $occurredAtUtc): ?int
    {
        $token = $this->tokens[$tokenHash] ?? null;

        if ($token === null || $token['used_at'] !== null || $token['expires_at'] <= $occurredAtUtc) {
            return null;
        }

        $this->tokens[$tokenHash]['used_at'] = $occurredAtUtc;

        return $token['referral_id'];
    }

    public function purgeForReferral(int $referralId): void
    {
        foreach ($this->tokens as $hash => $token) {
            if ($token['referral_id'] === $referralId) {
                unset($this->tokens[$hash]);
            }
        }
    }

    public function count(): int
    {
        return count($this->tokens);
    }

    /** @return array{referral_id: int, expires_at: string, used_at: ?string}|null */
    public function find(string $tokenHash): ?array
    {
        return $this->tokens[$tokenHash] ?? null;
    }
}
