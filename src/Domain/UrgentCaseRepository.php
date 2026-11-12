<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Persistence boundary for the urgent aggregate.
 *
 * Same two guarantees as the referral boundary: a transition is applied only if
 * the stored version still matches, and the history row is written in the same
 * transaction as the status change (WF-09).
 *
 * `acknowledge()` writes two things that are deliberately separate: the
 * acknowledgement record, which is the evidence, and the coordination status,
 * which is the summary. The record is what an audit reads.
 */
interface UrgentCaseRepository
{
    public function create(UrgentCase $case, Actor $actor, string $occurredAtUtc): UrgentCase;

    public function findById(int $id): ?UrgentCase;

    public function findByReference(string $reference): ?UrgentCase;

    /**
     * Append the decision and return it with its assigned id.
     *
     * Append-only: there is no update and no delete. A change of mind is another
     * row whose `supersedes_decision_id` points at the one it replaces.
     */
    public function recordUrgentDecision(UrgentDecision $decision): UrgentDecision;

    /** The decision that nothing supersedes, or null when none was recorded. */
    public function currentUrgentDecision(int $caseId): ?UrgentDecision;

    /** @return list<array{decision: UrgentDecision, decidedBy: string, superseded: bool}> */
    public function urgentDecisionHistory(int $caseId): array;

    /**
     * The board: cases that still need somebody, most urgent first.
     *
     * Ordering is `unacknowledged` before everything else and then oldest first,
     * because the two facts that matter are "nobody has picked this up" and "it has
     * been sitting for longer". Closed cases are not on it.
     *
     * @return list<UrgentCase>
     */
    public function openCases(int $limit = 50): array;

    /**
     * Everything that happened to one case, oldest first, across the five history
     * tables. Read-only and derived: it is a view of records that are each written
     * by their own command.
     *
     * @return list<UrgentTimelineEntry>
     */
    public function timeline(int $caseId): array;

    public function transitionUrgency(
        UrgentCase $case,
        UrgentStatus $target,
        Actor $actor,
        ?string $reason,
        string $occurredAtUtc,
    ): UrgentCase;

    /**
     * Record that a named person has seen the case, and move coordination from
     * unacknowledged to acknowledged. Calling it twice is refused: the first
     * acknowledgement is the one that counts (WF-05).
     */
    public function acknowledge(
        UrgentCase $case,
        Actor $actor,
        string $channel,
        ?string $note,
        string $occurredAtUtc,
    ): UrgentCase;

    /**
     * Append a contact attempt and, when the case was only acknowledged, move
     * coordination to `contacting` in the same transaction (WF-06).
     */
    public function recordContactAttempt(
        UrgentCase $case,
        UrgentContactAttempt $attempt,
    ): UrgentCase;

    /**
     * Append an escalation. Escalation recurs, so this never replaces an earlier
     * one; a case already escalated stays escalated and gains another row.
     */
    public function escalate(
        UrgentCase $case,
        Actor $actor,
        int $level,
        string $reason,
        ?int $assignToUserId,
        string $occurredAtUtc,
    ): UrgentCase;
}
