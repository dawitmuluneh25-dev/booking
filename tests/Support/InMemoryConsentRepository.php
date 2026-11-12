<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Actor;
use App\Domain\ConsentNotPermittedException;
use App\Domain\ConsentRepository;
use App\Domain\ProcedureConsent;

/**
 * In-memory consent boundary.
 *
 * Behaves like the MySQL one where it matters to a command: the current record is
 * the row nothing supersedes, withdrawal lands on the row and not on a copy of the
 * history, and withdrawing twice is refused.
 */
final class InMemoryConsentRepository implements ConsentRepository
{
    /** @var array<int, ProcedureConsent> */
    private array $consents = [];

    private int $nextId = 1;

    /** @param array<int, string> $recorderNames */
    public function __construct(private readonly array $recorderNames = [])
    {
    }

    public function record(ProcedureConsent $consent): ProcedureConsent
    {
        $stored = $consent->withIdentity($this->nextId);
        $this->nextId++;
        $this->consents[$stored->id] = $stored;

        return $stored;
    }

    public function findById(int $id): ?ProcedureConsent
    {
        return $this->consents[$id] ?? null;
    }

    public function current(int $referralId): ?ProcedureConsent
    {
        $superseded = [];

        foreach ($this->consents as $consent) {
            if ($consent->supersedesId !== null) {
                $superseded[$consent->supersedesId] = true;
            }
        }

        $current = null;

        foreach ($this->consents as $consent) {
            if ($consent->referralId !== $referralId || isset($superseded[$consent->id])) {
                continue;
            }

            $current = $consent;
        }

        return $current;
    }

    public function history(int $referralId): array
    {
        $history = [];

        foreach ($this->consents as $consent) {
            if ($consent->referralId !== $referralId) {
                continue;
            }

            $history[] = [
                'consent' => $consent,
                'recordedBy' => $this->recorderNames[$consent->recordedByUserId]
                    ?? 'User ' . $consent->recordedByUserId,
            ];
        }

        usort(
            $history,
            static fn (array $left, array $right): int => [$left['consent']->consentedAtUtc, $left['consent']->id]
                <=> [$right['consent']->consentedAtUtc, $right['consent']->id],
        );

        return $history;
    }

    public function withdraw(int $consentId, Actor $actor, string $reason, string $occurredAtUtc): void
    {
        $consent = $this->consents[$consentId] ?? null;

        if ($consent === null) {
            throw new ConsentNotPermittedException(null, 'No such consent record.');
        }

        if ($consent->isWithdrawn()) {
            throw new ConsentNotPermittedException(null, 'This agreement was already withdrawn.');
        }

        $this->consents[$consentId] = $consent->withWithdrawal($occurredAtUtc, $actor->userId, $reason);
    }
}
