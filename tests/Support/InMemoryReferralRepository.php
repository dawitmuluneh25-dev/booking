<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Actor;
use App\Domain\ClinicalDecision;
use App\Domain\ClinicalDecisionRecord;
use App\Domain\ClinicalPriorityChange;
use App\Domain\OutboundMessage;
use App\Domain\Referral;
use App\Domain\ReferralNotFoundException;
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

    /** @var list<ClinicalPriorityChange> */
    private array $priorityChanges = [];

    private int $nextId = 1;

    /**
     * @param array<int, string> $reviewerNames user id => display name, so a page
     *                                          test can assert the history names
     *                                          the person who decided
     */
    public function __construct(private readonly array $reviewerNames = [])
    {
    }

    /** @param list<OutboundMessage> $messages */
    public function create(
        Referral $referral,
        Actor $actor,
        string $occurredAtUtc,
        array $messages = [],
    ): Referral {
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

    /** @param list<OutboundMessage> $messages */
    public function transition(
        Referral $referral,
        ReferralStatus $target,
        Actor $actor,
        ?string $reason,
        string $occurredAtUtc,
        array $messages = [],
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

    /** @return list<Referral> */
    public function approvedForScheduling(int $limit = 50): array
    {
        $referrals = [];

        foreach ($this->referrals as $referral) {
            if ($referral->status === ReferralStatus::ApprovedForScheduling) {
                $referrals[] = $referral;
            }
        }

        return array_slice($referrals, 0, max(1, $limit));
    }
    /** @return list<Referral> */
    public function queued(int $limit = 100): array
    {
        $queued = [];

        foreach ($this->referrals as $referral) {
            $inQueue = $referral->status === ReferralStatus::Submitted
                || $referral->status === ReferralStatus::Received
                || $referral->status === ReferralStatus::AdministrativeReview
                || $referral->status === ReferralStatus::AwaitingInformation;

            if ($inQueue) {
                $queued[] = $referral;
            }
        }

        return array_slice($queued, 0, max(1, $limit));
    }
    /** @return list<Referral> */
    public function awaitingClinicalReview(int $limit = 100): array
    {
        $waiting = [];

        foreach ($this->referrals as $referral) {
            if ($referral->status === ReferralStatus::ClinicalReview) {
                $waiting[] = $referral;
            }
        }

        return array_slice($waiting, 0, max(1, $limit));
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

    /** @return list<ClinicalDecisionRecord> */
    public function decisionHistory(int $referralId): array
    {
        $records = [];

        foreach ($this->decisions as $decision) {
            if ($decision->referralId !== $referralId) {
                continue;
            }

            $records[] = new ClinicalDecisionRecord(
                decision: $decision,
                reviewerName: $this->reviewerNames[$decision->decidedByUserId]
                    ?? 'User #' . $decision->decidedByUserId,
                supersededById: $this->supersederOf($decision->id),
            );
        }

        usort(
            $records,
            static fn (ClinicalDecisionRecord $a, ClinicalDecisionRecord $b): int =>
                [$a->decision->decidedAtUtc, $a->decision->id] <=> [$b->decision->decidedAtUtc, $b->decision->id],
        );

        return $records;
    }

    public function assignClinicalPriority(int $referralId, string $priority): void
    {
        $stored = $this->referrals[$referralId] ?? null;

        if ($stored === null) {
            throw new ReferralNotFoundException('#' . $referralId);
        }

        $this->referrals[$referralId] = $stored->withClinicalPriority($priority);
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

    /** The id of the decision that replaced this one, if any. */
    private function supersederOf(int $decisionId): ?int
    {
        foreach ($this->decisions as $decision) {
            if ($decision->supersedesId === $decisionId) {
                return $decision->id;
            }
        }

        return null;
    }

    public function recordPriorityChange(ClinicalPriorityChange $change): ClinicalPriorityChange
    {
        if (!isset($this->referrals[$change->referralId])) {
            throw new ReferralNotFoundException('#' . $change->referralId);
        }

        $stored = new ClinicalPriorityChange(
            id: count($this->priorityChanges) + 1,
            referralId: $change->referralId,
            fromPriority: $change->fromPriority,
            toPriority: $change->toPriority,
            reason: $change->reason,
            changedByUserId: $change->changedByUserId,
            changedAtUtc: $change->changedAtUtc,
        );

        $this->priorityChanges[] = $stored;

        return $stored;
    }

    /** @return list<array{change: ClinicalPriorityChange, changedBy: string}> */
    public function priorityChangeHistory(int $referralId): array
    {
        $history = [];

        foreach ($this->priorityChanges as $change) {
            if ($change->referralId !== $referralId) {
                continue;
            }

            $history[] = [
                'change' => $change,
                'changedBy' => $this->reviewerNames[$change->changedByUserId] ?? 'Unknown user',
            ];
        }

        return $history;
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
