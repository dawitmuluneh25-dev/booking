<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A stranger's request for a referring account, and the record of what was
 * decided about it.
 *
 * Read-only. The status does not change by assignment: a decision is written by a
 * statement that only matches a `pending` row, so two people clicking "approve"
 * at once cannot both succeed.
 *
 * `note` is free text from an unauthenticated visitor. It is stored escaped-free
 * and must be escaped where it is rendered; nothing in this class treats it as
 * trusted.
 */
final class AccountRequest
{
    public function __construct(
        public readonly int $id,
        public readonly string $reference,
        public readonly string $fullName,
        public readonly string $email,
        public readonly string $practiceName,
        public readonly string $practiceCity,
        public readonly ?string $registrationNo,
        public readonly ?string $note,
        public readonly AccountRequestStatus $status,
        public readonly string $createdAtUtc,
        public readonly ?string $decidedAtUtc = null,
        public readonly ?int $decidedByUserId = null,
        public readonly ?string $decisionReason = null,
        public readonly ?int $createdUserId = null,
    ) {
    }

    public function withIdentity(int $id): self
    {
        return new self(
            id: $id,
            reference: $this->reference,
            fullName: $this->fullName,
            email: $this->email,
            practiceName: $this->practiceName,
            practiceCity: $this->practiceCity,
            registrationNo: $this->registrationNo,
            note: $this->note,
            status: $this->status,
            createdAtUtc: $this->createdAtUtc,
            decidedAtUtc: $this->decidedAtUtc,
            decidedByUserId: $this->decidedByUserId,
            decisionReason: $this->decisionReason,
            createdUserId: $this->createdUserId,
        );
    }

    /**
     * The registration number the professional register will hold, or a value that
     * is visibly provisional.
     *
     * `physician_profiles.practice_number` is required and unique, while the form
     * makes the registration number optional on purpose — a referrer who does not
     * have it to hand should still be able to ask for an account. The fallback
     * therefore encodes the request reference so that an unverified provisional
     * profile is obvious in the register rather than looking like a real number
     * somebody mistyped.
     */
    public function provisionalPracticeNumber(): string
    {
        $registration = trim((string) $this->registrationNo);

        return $registration !== '' ? $registration : 'pending-' . mb_strtolower($this->reference);
    }

    public function isPending(): bool
    {
        return $this->status === AccountRequestStatus::Pending;
    }
}
