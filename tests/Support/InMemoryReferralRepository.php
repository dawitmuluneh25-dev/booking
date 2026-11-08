<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Actor;
use App\Domain\ClinicalDecision;
use App\Domain\Referral;
use App\Domain\ReferralRepository;
use App\Domain\ReferralStatus;
use App\Domain\ReferralTransitionException;
use RuntimeException;

/**
 * A referral store with the same guarantees as the PDO adapter, without MySQL.
 *
 * It enforces the two rules the database enforces — the version must match, and
 * the transition must be allowed — so a command test fails here for the same
 * reason it would fail against MySQL, rather than passing on a permissive fake.
 */
final class InMemoryReferralRepository implements ReferralRepository
{
    /** @var array<int, Referral> */
    private array $referrals = [];

    /** @var list<array{referral_id: int, from: ?string, to: string, actor_role: string, reason: ?string, occurred_at: string}> */
    private array $history = [];

    /** @var list<ClinicalDecision> */
    private array $decisions = [];

    private int $nextId = 1;

    public function create(Referral $referral, Actor $actor, string $occurredAtUtc): Referral
    {
        $stored = $referral->withIdentity($this->nextId);
        $this->nextId++;
        $this->referrals[$stored->id] = $stored;

        $this->history[] = [
            'referral_id' => $stored->id,
            'from' => null,
            'to' => $stored->status->value,
            'actor_role' => $actor->role->value,
            'reason' => null,
            'occurred_at' => $occurredAtUtc,
        ];

        return $stored;
    }

    public function findByReference(string $reference): ?Referral
    {
        foreach ($this->referrals as $referral) {
            if ($referral->reference === $reference) {
                return $referral;
            }
        }

        return null;
    }

    public function findById(int $id): ?Referral
    {
        return $this->referrals[$id] ?? null;
    }

    public function transition(
        Referral $referral,
        ReferralStatus $target,
        Actor $actor,
        ?string $reason,
        string $occurredAtUtc,
    ): Referral {
        $stored = $this->referrals[$referral->id] ?? null;

        if ($stored === null || $stored->version !== $referral->version) {
            throw new ReferralTransitionException(
                $referral->status,
                $target,
                'The referral changed while you were working on it. Reload and try again.',
            );
        }

        if (!$stored->status->canTransitionTo($target)) {
            throw new ReferralTransitionException($stored->status, $target);
        }

        if (!in_array($actor->role, $stored->status->allowedActors($target), true)) {
            throw ReferralTransitionException::forActor($stored->status, $target, $actor->role);
        }

        if ($stored->status->requiresReason($target) && ($reason === null || trim($reason) === '')) {
            throw ReferralTransitionException::forMissingReason($stored->status, $target);
        }

        $moved = $stored->withStatus($target, $stored->version + 1);
        $this->referrals[$moved->id] = $moved;

        $this->history[] = [
            'referral_id' => $moved->id,
            'from' => $stored->status->value,
            'to' => $target->value,
            'actor_role' => $actor->role->value,
            'reason' => $reason,
            'occurred_at' => $occurredAtUtc,
        ];

        return $moved;
    }

    /**
     * @return list<array{from: ?string, to: string, actor_role: string, reason: ?string, occurred_at: string}>
     */
    public function historyFor(int $referralId): array
    {
        $rows = [];
        foreach ($this->history as $row) {
            if ($row['referral_id'] === $referralId) {
                $rows[] = [
                    'from' => $row['from'],
                    'to' => $row['to'],
                    'actor_role' => $row['actor_role'],
                    'reason' => $row['reason'],
                    'occurred_at' => $row['occurred_at'],
                ];
            }
        }

        return $rows;
    }

    public function currentDecision(int $referralId): ?ClinicalDecision
    {
        $standing = null;

        foreach ($this->decisions as $decision) {
            if ($decision->referralId !== $referralId) {
                continue;
            }

            if ($this->isSuperseded($decision->id)) {
                continue;
            }

            if ($standing === null || $decision->decidedAtUtc >= $standing->decidedAtUtc) {
                $standing = $decision;
            }
        }

        return $standing;
    }

    public function recordDecision(ClinicalDecision $decision): ClinicalDecision
    {
        if ($decision->supersedesId !== null && $this->isSuperseded($decision->supersedesId)) {
            // Mirrors uq_decisions_supersedes: a decision is replaced at most once.
            throw new RuntimeException('That decision has already been superseded.');
        }

        $stored = $decision->withIdentity(count($this->decisions) + 1);
        $this->decisions[] = $stored;

        return $stored;
    }

    private function isSuperseded(int $decisionId): bool
    {
        foreach ($this->decisions as $decision) {
            if ($decision->supersedesId === $decisionId) {
                return true;
            }
        }

        return false;
    }

    public function count(): int
    {
        return count($this->referrals);
    }
}
