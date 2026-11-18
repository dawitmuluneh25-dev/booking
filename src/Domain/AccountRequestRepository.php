<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Storage for account requests.
 *
 * The two decision writes are conditional statements rather than read-then-write:
 * each matches only a `pending` row and reports whether it changed anything, so
 * two administrators acting at once cannot both succeed.
 */
interface AccountRequestRepository
{
    public function create(AccountRequest $request, string $createdAtUtc): AccountRequest;

    public function findById(int $id): ?AccountRequest;

    /** @return list<AccountRequest> oldest first — a queue is worked in the order it arrived */
    public function pending(int $limit = 100): array;

    /** @return list<AccountRequest> most recent decisions first */
    public function recentlyDecided(int $limit = 20): array;

    /**
     * An open or decided request for the same person and practice inside the window,
     * so a double submission is answered once rather than queued twice.
     */
    public function findRecentDuplicate(string $email, string $practiceName, string $sinceUtc): ?AccountRequest;

    public function referenceExists(string $reference): bool;

    /** @return bool false when the row was no longer pending — somebody else decided it */
    public function markApproved(
        int $id,
        int $decidedByUserId,
        int $createdUserId,
        string $decidedAtUtc,
        ?string $reason,
    ): bool;

    /** @return bool false when the row was no longer pending */
    public function markRejected(int $id, int $decidedByUserId, string $reason, string $decidedAtUtc): bool;

    /** @return int rows expired */
    public function expirePendingOlderThan(string $cutoffUtc): int;

    public function countPending(): int;
}
