<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Actor;
use App\Domain\UrgentCase;
use App\Domain\UrgentCaseRepository;
use App\Domain\UrgentCoordination;
use App\Domain\UrgentStatus;
use App\Domain\UrgentTransitionException;
use RuntimeException;

/**
 * Urgent store with the same refusals as the MySQL adapter: version-guarded
 * transitions, role checks, required reasons, and no second acknowledgement.
 */
final class InMemoryUrgentCaseRepository implements UrgentCaseRepository
{
    /** @var array<int, UrgentCase> */
    private array $cases = [];

    /** @var list<array{case_id: int, from: ?string, to: string, actor_role: string, reason: ?string}> */
    private array $history = [];

    /** @var list<array{case_id: int, user_id: int, channel: string, note: ?string}> */
    private array $acknowledgements = [];

    public function create(UrgentCase $case, Actor $actor, string $occurredAtUtc): UrgentCase
    {
        $stored = $case->withIdentity(count($this->cases) + 1);
        $this->cases[$stored->id] = $stored;

        $this->history[] = [
            'case_id' => $stored->id,
            'from' => null,
            'to' => $stored->urgency->value,
            'actor_role' => $actor->role->value,
            'reason' => null,
        ];

        return $stored;
    }

    public function findById(int $id): ?UrgentCase
    {
        return $this->cases[$id] ?? null;
    }

    public function transitionUrgency(
        UrgentCase $case,
        UrgentStatus $target,
        Actor $actor,
        ?string $reason,
        string $occurredAtUtc,
    ): UrgentCase {
        $stored = $this->cases[$case->id] ?? null;

        if ($stored === null || $stored->version !== $case->version) {
            throw new UrgentTransitionException(
                $case->urgency->value,
                $target->value,
                'The case changed while you were working on it. Reload and try again.',
            );
        }

        if (!$stored->urgency->canTransitionTo($target)) {
            throw new UrgentTransitionException($stored->urgency->value, $target->value);
        }

        if (!in_array($actor->role, $stored->urgency->allowedActors($target), true)) {
            throw UrgentTransitionException::forActor($stored->urgency, $target, $actor->role);
        }

        if ($stored->urgency->requiresReason($target) && ($reason === null || trim($reason) === '')) {
            throw UrgentTransitionException::forMissingReason($stored->urgency, $target);
        }

        $moved = $stored->withUrgency($target, $stored->version + 1);
        $this->cases[$moved->id] = $moved;

        $this->history[] = [
            'case_id' => $moved->id,
            'from' => $stored->urgency->value,
            'to' => $target->value,
            'actor_role' => $actor->role->value,
            'reason' => $reason,
        ];

        return $moved;
    }

    public function acknowledge(
        UrgentCase $case,
        Actor $actor,
        string $channel,
        ?string $note,
        string $occurredAtUtc,
    ): UrgentCase {
        $stored = $this->cases[$case->id] ?? null;

        if ($stored === null || $stored->version !== $case->version) {
            throw new UrgentTransitionException(
                $case->coordination->value,
                UrgentCoordination::Acknowledged->value,
                'Another person acknowledged this case first.',
                'coordination',
            );
        }

        if (!$stored->coordination->canTransitionTo(UrgentCoordination::Acknowledged)) {
            throw UrgentTransitionException::forCoordination(
                $stored->coordination,
                UrgentCoordination::Acknowledged,
                'This case has already been acknowledged.',
            );
        }

        $allowed = $stored->coordination->allowedActors(UrgentCoordination::Acknowledged);

        if (!in_array($actor->role, $allowed, true)) {
            throw UrgentTransitionException::forCoordination(
                $stored->coordination,
                UrgentCoordination::Acknowledged,
                sprintf('The %s role may not acknowledge this case.', $actor->role->value),
            );
        }

        $this->acknowledgements[] = [
            'case_id' => $stored->id,
            'user_id' => $actor->userId,
            'channel' => $channel,
            'note' => $note,
        ];

        $moved = $stored->withCoordination(UrgentCoordination::Acknowledged, $stored->version + 1);
        $this->cases[$moved->id] = $moved;

        return $moved;
    }

    /** @return list<array{case_id: int, from: ?string, to: string, actor_role: string, reason: ?string}> */
    public function historyFor(int $caseId): array
    {
        $rows = [];

        foreach ($this->history as $row) {
            if ($row['case_id'] === $caseId) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return list<array{case_id: int, user_id: int, channel: string, note: ?string}> */
    public function acknowledgementsFor(int $caseId): array
    {
        $rows = [];

        foreach ($this->acknowledgements as $row) {
            if ($row['case_id'] === $caseId) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function count(): int
    {
        return count($this->cases);
    }

    public function caseOrFail(int $id): UrgentCase
    {
        $case = $this->cases[$id] ?? null;

        if ($case === null) {
            throw new RuntimeException('No urgent case #' . $id);
        }

        return $case;
    }
}
