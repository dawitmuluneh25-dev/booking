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
