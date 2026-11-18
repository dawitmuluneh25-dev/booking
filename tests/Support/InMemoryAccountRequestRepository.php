<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\AccountRequest;
use App\Domain\AccountRequestRepository;
use App\Domain\AccountRequestStatus;

/**
 * The queue in an array, with the same conditional-write semantics as the SQL.
 *
 * `markApproved` and `markRejected` return false unless the stored row is still
 * pending, so a test can prove that a second decision is refused rather than
 * overwriting the first.
 */
final class InMemoryAccountRequestRepository implements AccountRequestRepository
{
    /** @var array<int, AccountRequest> */
    private array $requests = [];

    private int $nextId = 1;

    public function create(AccountRequest $request, string $createdAtUtc): AccountRequest
    {
        $stored = $request->withIdentity($this->nextId++);
        $this->requests[$stored->id] = $stored;

        return $stored;
    }

    public function findById(int $id): ?AccountRequest
    {
        return $this->requests[$id] ?? null;
    }

    public function pending(int $limit = 100): array
    {
        return array_slice($this->withStatus(AccountRequestStatus::Pending), 0, $limit);
    }

    public function recentlyDecided(int $limit = 20): array
    {
        $decided = array_values(array_filter(
            $this->requests,
            static fn (AccountRequest $request): bool => $request->status !== AccountRequestStatus::Pending,
        ));

        return array_slice($decided, 0, $limit);
    }

    public function findRecentDuplicate(string $email, string $practiceName, string $sinceUtc): ?AccountRequest
    {
        foreach ($this->requests as $request) {
            if (
                $request->email === $email
                && mb_strtolower($request->practiceName) === mb_strtolower($practiceName)
                && $request->createdAtUtc >= $sinceUtc
            ) {
                return $request;
            }
        }

        return null;
    }

    public function referenceExists(string $reference): bool
    {
        foreach ($this->requests as $request) {
            if ($request->reference === $reference) {
                return true;
            }
        }

        return false;
    }

    public function markApproved(
        int $id,
        int $decidedByUserId,
        int $createdUserId,
        string $decidedAtUtc,
        ?string $reason,
    ): bool {
        $request = $this->requests[$id] ?? null;

        if ($request === null || !$request->isPending()) {
            return false;
        }

        $this->requests[$id] = new AccountRequest(
            id: $request->id,
            reference: $request->reference,
            fullName: $request->fullName,
            email: $request->email,
            practiceName: $request->practiceName,
            practiceCity: $request->practiceCity,
            registrationNo: $request->registrationNo,
            note: $request->note,
            status: AccountRequestStatus::Approved,
            createdAtUtc: $request->createdAtUtc,
            decidedAtUtc: $decidedAtUtc,
            decidedByUserId: $decidedByUserId,
            decisionReason: $reason,
            createdUserId: $createdUserId,
        );

        return true;
    }

    public function markRejected(int $id, int $decidedByUserId, string $reason, string $decidedAtUtc): bool
    {
        $request = $this->requests[$id] ?? null;

        if ($request === null || !$request->isPending()) {
            return false;
        }

        $this->requests[$id] = new AccountRequest(
            id: $request->id,
            reference: $request->reference,
            fullName: $request->fullName,
            email: $request->email,
            practiceName: $request->practiceName,
            practiceCity: $request->practiceCity,
            registrationNo: $request->registrationNo,
            note: $request->note,
            status: AccountRequestStatus::Rejected,
            createdAtUtc: $request->createdAtUtc,
            decidedAtUtc: $decidedAtUtc,
            decidedByUserId: $decidedByUserId,
            decisionReason: $reason,
            createdUserId: null,
        );

        return true;
    }

    public function expirePendingOlderThan(string $cutoffUtc): int
    {
        $expired = 0;

        foreach ($this->requests as $id => $request) {
            if (!$request->isPending() || $request->createdAtUtc >= $cutoffUtc) {
                continue;
            }

            $this->requests[$id] = new AccountRequest(
                id: $request->id,
                reference: $request->reference,
                fullName: $request->fullName,
                email: $request->email,
                practiceName: $request->practiceName,
                practiceCity: $request->practiceCity,
                registrationNo: $request->registrationNo,
                note: $request->note,
                status: AccountRequestStatus::Expired,
                createdAtUtc: $request->createdAtUtc,
            );

            $expired++;
        }

        return $expired;
    }

    public function countPending(): int
    {
        return count($this->withStatus(AccountRequestStatus::Pending));
    }

    /** @return list<AccountRequest> */
    private function withStatus(AccountRequestStatus $status): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (AccountRequest $request): bool => $request->status === $status,
        ));
    }
}
