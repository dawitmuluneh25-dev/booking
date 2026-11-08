<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A referring physician's verified identity.
 *
 * Separate from `User` on purpose: the account answers "can this person sign in",
 * the profile answers "may we accept referrals from them". Suspending a profile
 * leaves the account intact, so the person can still sign in and see why they were
 * suspended rather than being locked out with no explanation.
 */
final class PhysicianProfile
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $practiceNumber,
        public readonly string $institution,
        public readonly ?string $specialty,
        public readonly PhysicianStatus $status,
        public readonly ?int $verifiedByUserId,
        public readonly ?string $verifiedAtUtc,
        public readonly ?string $reviewNote,
        public readonly string $createdAtUtc,
    ) {
    }

    public function withIdentity(int $id): self
    {
        return new self(
            id: $id,
            userId: $this->userId,
            practiceNumber: $this->practiceNumber,
            institution: $this->institution,
            specialty: $this->specialty,
            status: $this->status,
            verifiedByUserId: $this->verifiedByUserId,
            verifiedAtUtc: $this->verifiedAtUtc,
            reviewNote: $this->reviewNote,
            createdAtUtc: $this->createdAtUtc,
        );
    }

    public function withStatus(
        PhysicianStatus $status,
        ?int $reviewerId,
        ?string $reviewedAtUtc,
        ?string $note,
    ): self {
        return new self(
            id: $this->id,
            userId: $this->userId,
            practiceNumber: $this->practiceNumber,
            institution: $this->institution,
            specialty: $this->specialty,
            status: $status,
            verifiedByUserId: $reviewerId,
            verifiedAtUtc: $reviewedAtUtc,
            reviewNote: $note,
            createdAtUtc: $this->createdAtUtc,
        );
    }

    public function mayRefer(): bool
    {
        return $this->status->grantsReferralAuthority();
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::intOf($row['id'] ?? null),
            userId: self::intOf($row['user_id'] ?? null),
            practiceNumber: self::stringOf($row['practice_number'] ?? null),
            institution: self::stringOf($row['institution'] ?? null),
            specialty: self::nullable($row['specialty'] ?? null),
            status: PhysicianStatus::from(self::stringOf($row['status'] ?? null)),
            verifiedByUserId: is_numeric($row['verified_by_user_id'] ?? null)
                ? (int) $row['verified_by_user_id']
                : null,
            verifiedAtUtc: self::nullable($row['verified_at'] ?? null),
            reviewNote: self::nullable($row['review_note'] ?? null),
            createdAtUtc: self::stringOf($row['created_at'] ?? null),
        );
    }

    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function stringOf(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullable(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
