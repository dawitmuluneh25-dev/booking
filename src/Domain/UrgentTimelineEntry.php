<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One thing that happened to an urgent case, from whichever table recorded it.
 *
 * The case has five history tables — acknowledgements, contact attempts,
 * escalations, coordination events and the status trail — because each answers a
 * different question and none of them could be a column on another. A coordinator
 * at 03:00 does not care which table a fact lives in; they need the sequence.
 *
 * So the reader merges the five into one ordered list and this object carries the
 * result. `label` is the short, already-composed substance of the row (the channel,
 * the level, the status move); `detail` is the free text somebody wrote, if any.
 * Composing the sentence here rather than in SQL keeps the query dumb and the
 * wording reviewable in PHP.
 */
final class UrgentTimelineEntry
{
    /** The five kinds are the five tables; anything else is a programming error. */
    public const ACKNOWLEDGEMENT = 'acknowledgement';
    public const CONTACT = 'contact';
    public const ESCALATION = 'escalation';
    public const EVENT = 'event';
    public const STATUS = 'status';
    public const DECISION = 'decision';
    public const COORDINATION = 'coordination';

    public function __construct(
        public readonly string $kind,
        public readonly string $occurredAtUtc,
        public readonly string $actorName,
        public readonly string $label,
        public readonly ?string $detail,
    ) {
    }

    /**
     * What happened, in words a person reads.
     *
     * Each phrasing names the act rather than the table, because "a row appeared
     * in urgent_escalations" is not something anybody can act on.
     */
    public function description(): string
    {
        return match ($this->kind) {
            self::ACKNOWLEDGEMENT => 'Acknowledged — ' . $this->label,
            self::CONTACT => 'Contact attempt — ' . $this->label,
            self::ESCALATION => 'Escalated — ' . $this->label,
            self::EVENT => 'Coordination — ' . $this->label,
            self::STATUS => 'Status ' . $this->label,
            self::COORDINATION => 'Coordination ' . $this->label,
            // The stored decision type is a slug; the reader gets the label.
            self::DECISION => 'Decision — '
                . (UrgentDecisionType::tryFrom($this->label)?->label() ?? $this->label),
            default => $this->label,
        };
    }

    /**
     * Whether this entry is the delivery of a notification rather than a person
     * acting. Nothing in the urgent pathway produces one today (the outbox has no
     * writer here), and the answer stays available so a future dispatcher cannot
     * quietly count as an acknowledgement (WF-05).
     */
    public function isAcknowledgement(): bool
    {
        return $this->kind === self::ACKNOWLEDGEMENT;
    }
}
