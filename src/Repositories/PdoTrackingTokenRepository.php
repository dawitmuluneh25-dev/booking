<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\TrackingTokenRepository;
use PDO;

/**
 * MySQL implementation of status-link storage.
 *
 * `consume` is a single conditional UPDATE. That is not a stylistic preference:
 * it is the only form that makes "has this link been used" and "mark it used" one
 * atomic fact, so two requests racing on the same URL cannot both succeed. The
 * three conditions are all in the WHERE clause - the hash, `used_at IS NULL` and
 * `expires_at > now` - which means an expired token and a redeemed token fail in
 * exactly the same way and at exactly the same point.
 */
final class PdoTrackingTokenRepository implements TrackingTokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function issue(
        int $referralId,
        string $tokenHash,
        string $expiresAtUtc,
        ?int $issuedByUserId,
        string $createdAtUtc,
    ): void {
        $insert = $this->pdo->prepare(
            "INSERT INTO referral_tracking_tokens
                (referral_id, purpose, token_hash, expires_at, issued_by_user_id, created_at)
             VALUES
                (:referral_id, 'status_link', :token_hash, :expires_at, :issued_by, :created_at)"
        );

        $insert->bindValue('referral_id', $referralId, PDO::PARAM_INT);
        $insert->bindValue('token_hash', $tokenHash);
        $insert->bindValue('expires_at', $expiresAtUtc);
        $insert->bindValue(
            'issued_by',
            $issuedByUserId,
            $issuedByUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT,
        );
        $insert->bindValue('created_at', $createdAtUtc);
        $insert->execute();
    }

    public function consume(string $tokenHash, string $occurredAtUtc): ?int
    {
        $update = $this->pdo->prepare(
            'UPDATE referral_tracking_tokens
                SET used_at = :used_at
              WHERE token_hash = :token_hash
                AND used_at IS NULL
                AND expires_at > :used_at'
        );
        $update->execute(['used_at' => $occurredAtUtc, 'token_hash' => $tokenHash]);

        if ($update->rowCount() !== 1) {
            return null;
        }

        $select = $this->pdo->prepare(
            'SELECT referral_id FROM referral_tracking_tokens WHERE token_hash = :token_hash'
        );
        $select->execute(['token_hash' => $tokenHash]);
        $value = $select->fetchColumn();

        return is_numeric($value) ? (int) $value : null;
    }

    public function purgeForReferral(int $referralId): void
    {
        $delete = $this->pdo->prepare('DELETE FROM referral_tracking_tokens WHERE referral_id = :id');
        $delete->execute(['id' => $referralId]);
    }
}
