<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\PhysicianProfile;
use App\Domain\PhysicianProfileRepository;
use App\Domain\PhysicianStatus;

/** Physician profiles without MySQL, for command tests. */
final class InMemoryPhysicianProfileRepository implements PhysicianProfileRepository
{
    /** @var array<int, PhysicianProfile> */
    private array $profiles = [];

    public function findByUserId(int $userId): ?PhysicianProfile
    {
        foreach ($this->profiles as $profile) {
            if ($profile->userId === $userId) {
                return $profile;
            }
        }

        return null;
    }

    public function findById(int $id): ?PhysicianProfile
    {
        return $this->profiles[$id] ?? null;
    }

    public function pending(): array
    {
        return array_values(array_filter(
            $this->profiles,
            static fn (PhysicianProfile $profile): bool => $profile->status === PhysicianStatus::Pending,
        ));
    }

    public function create(PhysicianProfile $profile, string $createdAtUtc): PhysicianProfile
    {
        $stored = $profile->withIdentity(count($this->profiles) + 1);
        $this->profiles[$stored->id] = $stored;

        return $stored;
    }

    public function review(
        PhysicianProfile $profile,
        PhysicianStatus $status,
        int $reviewerUserId,
        ?string $note,
        string $reviewedAtUtc,
    ): PhysicianProfile {
        $stored = $profile->withStatus($status, $reviewerUserId, $reviewedAtUtc, $note);
        $this->profiles[$stored->id] = $stored;

        return $stored;
    }
}
