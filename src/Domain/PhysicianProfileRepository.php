<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Verified referrer records.
 *
 * `findByUserId` is the query every referrer-facing request makes, so it must be
 * cheap and indexed; the verification queue reads `pending()` in arrival order.
 */
interface PhysicianProfileRepository
{
    public function findByUserId(int $userId): ?PhysicianProfile;

    public function findById(int $id): ?PhysicianProfile;

    /** @return list<PhysicianProfile> oldest first */
    public function pending(): array;

    /**
     * The queue, filtered by status, with the account's name and address.
     *
     *
ull means every profile: an administrator reviewing a suspension needs to
     * see the verified records as well as the pending ones, and a queue that can
     * only show one status is a queue that hides the decision it is asking about.
     *
     * @return list<PhysicianQueueEntry> oldest first
     */
    public function queued(?PhysicianStatus $status = null): array;

    /** Persist a status change and return the stored profile. */
    public function review(
        PhysicianProfile $profile,
        PhysicianStatus $status,
        int $reviewerUserId,
        ?string $note,
        string $reviewedAtUtc,
    ): PhysicianProfile;

    public function create(PhysicianProfile $profile, string $createdAtUtc): PhysicianProfile;
}
