<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Actor;
use App\Domain\Role;
use App\Domain\UrgentCase;
use App\Domain\UrgentCaseRepository;
use App\Domain\UrgentCaseNotFoundException;
use App\Domain\UrgentContactAttempt;
use App\Domain\UrgentCoordination;
use App\Domain\UrgentDecision;
use App\Domain\UrgentDecisionType;
use App\Domain\UrgentStatus;
use App\Domain\UrgentTimelineEntry;
use App\Domain\UrgentTransitionException;
use RuntimeException;

/**
 * Urgent store with the same refusals as the MySQL adapter: version-guarded
 * transitions, role checks, required reasons, no second acknowledgement, and a
 * coordination event only where the status model names one.
 *
 * `$userNames` mirrors the join the MySQL adapter does against `users`, because the
 * timeline names the person who acted and a role is not a name.
 */
final class InMemoryUrgentCaseRepository implements UrgentCaseRepository
{
    /** @var array<int, UrgentCase> */
    private array $cases = [];

    /** @var list<array{case_id: int, from: ?string, to: string, actor_user_id: int, actor_role: string, reason: ?string, at: string}> */
    private array $history = [];

    /** @var list<array{case_id: int, user_id: int, channel: string, note: ?string, at: string}> */
    private array $acknowledgements = [];

    /** @var list<array{case_id: int, user_id: int, contact_role: string, channel: string, outcome: string, phone: ?string, notes: ?string, at: string}> */
    private array $contacts = [];

    /** @var list<array{case_id: int, user_id: int, level: int, reason: string, assignee: ?int, at: string}> */
    private array $escalations = [];

    /** @var list<array{case_id: int, event_type: string, user_id: int, note: string, at: string}> */
    private array $events = [];

    /** @var array<int, string> */
    private array $closedAt = [];

    /** @var list<array{id: int, case_id: int, type: UrgentDecisionType, rationale: string, user_id: int, at: string, resulting: ?string, supersedes: ?int}> */
    private array $decisions = [];

    /** @var list<array{case_id: int, from: UrgentCoordination, to: UrgentCoordination, user_id: int, role: Role, reason: ?string, at: string}> */
    private array $coordinationHistory = [];

    /** @param array<int, string> $userNames userId => name, the stand-in for the users join */
    public function __construct(private readonly array $userNames = [])
    {
    }

    public function create(UrgentCase $case, Actor $actor, string $occurredAtUtc): UrgentCase
    {
        $stored = $case->withIdentity(count($this->cases) + 1);
        $this->cases[$stored->id] = $stored;

        $this->history[] = [
            'case_id' => $stored->id,
            'from' => null,
            'to' => $stored->urgency->value,
            'actor_user_id' => $actor->userId,
            'actor_role' => $actor->role->value,
            'reason' => null,
            'at' => $occurredAtUtc,
        ];

        return $stored;
    }

    public function findById(int $id): ?UrgentCase
    {
        return $this->cases[$id] ?? null;
    }

    public function findByReference(string $reference): ?UrgentCase
    {
        foreach ($this->cases as $case) {
            if ($case->reference === $reference) {
                return $case;
            }
        }

        return null;
    }

    public function openCases(int $limit = 50): array
    {
        $open = array_values(array_filter(
            $this->cases,
            static fn (UrgentCase $case): bool => $case->urgency !== UrgentStatus::Closed,
        ));

        usort($open, static function (UrgentCase $left, UrgentCase $right): int {
            $leftAcknowledged = $left->coordination === UrgentCoordination::Unacknowledged ? 0 : 1;
            $rightAcknowledged = $right->coordination === UrgentCoordination::Unacknowledged ? 0 : 1;

            return $leftAcknowledged <=> $rightAcknowledged
                ?: $left->receivedAtUtc <=> $right->receivedAtUtc
                ?: $left->id <=> $right->id;
        });

        return array_slice($open, 0, max(1, $limit));
    }

    public function timeline(int $caseId): array
    {
        $entries = [];

        foreach ($this->history as $row) {
            if ($row['case_id'] !== $caseId) {
                continue;
            }

            $entries[] = $this->entry(
                UrgentTimelineEntry::STATUS,
                $row['at'],
                $row['actor_user_id'],
                ($row['from'] ?? 'new') . ' → ' . $row['to'],
                $row['reason'],
            );
        }

        foreach ($this->acknowledgements as $row) {
            if ($row['case_id'] === $caseId) {
                $entries[] = $this->entry(
                    UrgentTimelineEntry::ACKNOWLEDGEMENT,
                    $row['at'],
                    $row['user_id'],
                    $row['channel'],
                    $row['note'],
                );
            }
        }

        foreach ($this->contacts as $row) {
            if ($row['case_id'] === $caseId) {
                $entries[] = $this->entry(
                    UrgentTimelineEntry::CONTACT,
                    $row['at'],
                    $row['user_id'],
                    $row['contact_role'] . ' · ' . $row['channel'] . ' · ' . $row['outcome']
                        . ($row['phone'] === null ? '' : ' · ' . $row['phone']),
                    $row['notes'],
                );
            }
        }

        foreach ($this->escalations as $row) {
            if ($row['case_id'] === $caseId) {
                $entries[] = $this->entry(
                    UrgentTimelineEntry::ESCALATION,
                    $row['at'],
                    $row['user_id'],
                    'level ' . $row['level'],
                    $row['reason'],
                );
            }
        }

        foreach ($this->coordinationHistory as $row) {
            if ($row['case_id'] === $caseId) {
                $entries[] = $this->entry(
                    UrgentTimelineEntry::COORDINATION,
                    $row['at'],
                    $row['user_id'],
                    $row['from']->value . ' → ' . $row['to']->value,
                    $row['reason'],
                );
            }
        }

        foreach ($this->decisions as $row) {
            if ($row['case_id'] === $caseId) {
                $entries[] = $this->entry(
                    UrgentTimelineEntry::DECISION,
                    $row['at'],
                    $row['user_id'],
                    $row['type']->value,
                    $row['rationale'],
                );
            }
        }

        foreach ($this->events as $row) {
            if ($row['case_id'] === $caseId) {
                $entries[] = $this->entry(
                    UrgentTimelineEntry::EVENT,
                    $row['at'],
                    $row['user_id'],
                    $row['event_type'],
                    $row['note'],
                );
            }
        }

        usort($entries, static fn (UrgentTimelineEntry $left, UrgentTimelineEntry $right): int
            => [$left->occurredAtUtc, $left->kind] <=> [$right->occurredAtUtc, $right->kind]);

        return $entries;
    }

    public function transitionCoordination(
        UrgentCase $case,
        UrgentCoordination $target,
        Actor $actor,
        ?string $reason,
        string $occurredAtUtc,
    ): UrgentCase {
        $current = $this->caseOrFail($case->id);

        if (!$current->coordination->canTransitionTo($target)) {
            throw new UrgentTransitionException(
                $current->coordination->value,
                $target->value,
                'That move is not open from where this case is.',
                'coordination',
            );
        }

        if (!in_array($actor->role, $current->coordination->allowedActors($target), true)) {
            throw new UrgentTransitionException(
                $current->coordination->value,
                $target->value,
                sprintf('The %s role may not perform this transition.', $actor->role->value),
                'coordination',
            );
        }

        $this->coordinationHistory[] = [
            'case_id' => $current->id,
            'from' => $current->coordination,
            'to' => $target,
            'user_id' => $actor->userId,
            'role' => $actor->role,
            'reason' => $reason,
            'at' => $occurredAtUtc,
        ];

        $moved = $current->withCoordination($target, $current->version + 1);
        $this->cases[$current->id] = $moved;

        return $moved;
    }

    public function recordUrgentDecision(UrgentDecision $decision): UrgentDecision
    {
        $id = count($this->decisions) + 1;

        $this->decisions[] = [
            'id' => $id,
            'case_id' => $decision->urgentCaseId,
            'type' => $decision->type,
            'rationale' => $decision->rationale,
            'user_id' => $decision->decidedByUserId,
            'at' => $decision->decidedAtUtc,
            'resulting' => $decision->resultingStatus,
            'supersedes' => $decision->supersedesId,
        ];

        return new UrgentDecision(
            id: $id,
            urgentCaseId: $decision->urgentCaseId,
            type: $decision->type,
            rationale: $decision->rationale,
            decidedByUserId: $decision->decidedByUserId,
            decidedAtUtc: $decision->decidedAtUtc,
            resultingStatus: $decision->resultingStatus,
            supersedesId: $decision->supersedesId,
        );
    }

    public function currentUrgentDecision(int $caseId): ?UrgentDecision
    {
        $superseded = [];

        foreach ($this->decisions as $row) {
            if ($row['case_id'] === $caseId && $row['supersedes'] !== null) {
                $superseded[$row['supersedes']] = true;
            }
        }

        $current = null;

        foreach ($this->decisions as $row) {
            if ($row['case_id'] !== $caseId || isset($superseded[$row['id']])) {
                continue;
            }

            $current = $row;
        }

        return $current === null ? null : $this->decisionFromRow($current);
    }

    /** @return list<array{decision: UrgentDecision, decidedBy: string, superseded: bool}> */
    public function urgentDecisionHistory(int $caseId): array
    {
        $superseded = [];

        foreach ($this->decisions as $row) {
            if ($row['case_id'] === $caseId && $row['supersedes'] !== null) {
                $superseded[$row['supersedes']] = true;
            }
        }

        $history = [];

        foreach ($this->decisions as $row) {
            if ($row['case_id'] !== $caseId) {
                continue;
            }

            $history[] = [
                'decision' => $this->decisionFromRow($row),
                'decidedBy' => $this->userNames[$row['user_id']] ?? 'Unknown user',
                'superseded' => isset($superseded[$row['id']]),
            ];
        }

        return $history;
    }

    /** @param array{id: int, case_id: int, type: UrgentDecisionType, rationale: string, user_id: int, at: string, resulting: ?string, supersedes: ?int} $row */
    private function decisionFromRow(array $row): UrgentDecision
    {
        return new UrgentDecision(
            id: $row['id'],
            urgentCaseId: $row['case_id'],
            type: $row['type'],
            rationale: $row['rationale'],
            decidedByUserId: $row['user_id'],
            decidedAtUtc: $row['at'],
            resultingStatus: $row['resulting'],
            supersedesId: $row['supersedes'],
        );
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
            'actor_user_id' => $actor->userId,
            'actor_role' => $actor->role->value,
            'reason' => $reason,
            'at' => $occurredAtUtc,
        ];

        $eventType = $target->occurredEventType();

        if ($eventType !== null) {
            $this->events[] = [
                'case_id' => $moved->id,
                'event_type' => $eventType,
                'user_id' => $actor->userId,
                'note' => $reason !== null && trim($reason) !== '' ? $reason : $target->label(),
                'at' => $occurredAtUtc,
            ];
        }

        if ($target === UrgentStatus::Closed) {
            $this->closedAt[$moved->id] = $occurredAtUtc;
        }

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
            'at' => $occurredAtUtc,
        ];

        $moved = $stored->withCoordination(UrgentCoordination::Acknowledged, $stored->version + 1);
        $this->cases[$moved->id] = $moved;

        return $moved;
    }

    public function recordContactAttempt(
        UrgentCase $case,
        UrgentContactAttempt $attempt,
    ): UrgentCase {
        $stored = $this->cases[$case->id] ?? null;

        if ($stored === null) {
            throw new UrgentCaseNotFoundException($case->id);
        }

        $target = UrgentCoordination::Contacting;
        $moves = $stored->coordination->canTransitionTo($target);

        if (!$moves && $stored->coordination !== UrgentCoordination::Contacting) {
            throw UrgentTransitionException::forCoordination(
                $stored->coordination,
                $target,
                'This case is ' . $stored->coordination->value . ' and cannot take a new contact attempt.',
            );
        }

        $this->contacts[] = [
            'case_id' => $stored->id,
            'user_id' => $attempt->attemptedByUserId,
            'contact_role' => $attempt->contactRole,
            'channel' => $attempt->channel,
            'outcome' => $attempt->outcome,
            'phone' => $attempt->contactPhone,
            'notes' => $attempt->notes,
            'at' => $attempt->attemptedAtUtc,
        ];

        if (!$moves) {
            return $stored;
        }

        $moved = $stored->withCoordination($target, $stored->version + 1);
        $this->cases[$moved->id] = $moved;

        return $moved;
    }

    public function escalate(
        UrgentCase $case,
        Actor $actor,
        int $level,
        string $reason,
        ?int $assignToUserId,
        string $occurredAtUtc,
    ): UrgentCase {
        $stored = $this->cases[$case->id] ?? null;

        if ($stored === null) {
            throw new UrgentCaseNotFoundException($case->id);
        }

        $target = UrgentCoordination::Escalated;
        $moves = $stored->coordination->canTransitionTo($target);

        if (!$moves && $stored->coordination !== UrgentCoordination::Escalated) {
            throw UrgentTransitionException::forCoordination(
                $stored->coordination,
                $target,
                'This case is ' . $stored->coordination->value . ' and cannot be escalated.',
            );
        }

        if (!in_array($actor->role, $stored->coordination->escalationActors(), true)) {
            throw UrgentTransitionException::forCoordination(
                $stored->coordination,
                $target,
                sprintf('The %s role may not escalate this case.', $actor->role->value),
            );
        }

        $this->escalations[] = [
            'case_id' => $stored->id,
            'user_id' => $actor->userId,
            'level' => $level,
            'reason' => $reason,
            'assignee' => $assignToUserId,
            'at' => $occurredAtUtc,
        ];

        if (!$moves) {
            return $stored;
        }

        $moved = $stored->withCoordination($target, $stored->version + 1);
        $this->cases[$moved->id] = $moved;

        return $moved;
    }

    /** @return list<array{case_id: int, user_id: int, level: int, reason: string, assignee: ?int, at: string}> */
    public function escalationsFor(int $caseId): array
    {
        return array_values(array_filter(
            $this->escalations,
            static fn (array $row): bool => $row['case_id'] === $caseId,
        ));
    }

    /** @return list<array{case_id: int, user_id: int, contact_role: string, channel: string, outcome: string, phone: ?string, notes: ?string, at: string}> */
    public function contactsFor(int $caseId): array
    {
        return array_values(array_filter(
            $this->contacts,
            static fn (array $row): bool => $row['case_id'] === $caseId,
        ));
    }

    /** @return list<array{case_id: int, from: ?string, to: string, actor_user_id: int, actor_role: string, reason: ?string, at: string}> */
    public function historyFor(int $caseId): array
    {
        return array_values(array_filter(
            $this->history,
            static fn (array $row): bool => $row['case_id'] === $caseId,
        ));
    }

    /** @return list<array{case_id: int, user_id: int, channel: string, note: ?string, at: string}> */
    public function acknowledgementsFor(int $caseId): array
    {
        return array_values(array_filter(
            $this->acknowledgements,
            static fn (array $row): bool => $row['case_id'] === $caseId,
        ));
    }

    /** @return list<array{case_id: int, event_type: string, user_id: int, note: string, at: string}> */
    public function eventsFor(int $caseId): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (array $row): bool => $row['case_id'] === $caseId,
        ));
    }

    public function closedAtFor(int $caseId): ?string
    {
        return $this->closedAt[$caseId] ?? null;
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

    private function entry(string $kind, string $at, int $userId, string $label, ?string $detail): UrgentTimelineEntry
    {
        return new UrgentTimelineEntry(
            kind: $kind,
            occurredAtUtc: $at,
            actorName: $this->userNames[$userId] ?? 'Unknown user',
            label: $label,
            detail: $detail === '' ? null : $detail,
        );
    }
}
