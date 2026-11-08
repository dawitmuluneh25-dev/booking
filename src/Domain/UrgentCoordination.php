<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How far the operational side has got with an urgent case.
 *
 * This axis is separate from `UrgentStatus` on purpose. A case can be accepted
 * clinically while nobody has yet reached the family, and it can be acknowledged
 * while the radiologist is still reviewing it. Collapsing the two axes into one
 * field is how "acknowledged" ends up meaning "clinically decided", which is the
 * confusion WF-05 exists to prevent.
 *
 * Nothing here can be reached by sending a message. The first transition out of
 * `unacknowledged` requires an identified person to say they have seen the case,
 * and that is recorded as a `urgent_acknowledgements` row, not as a delivery
 * receipt (WF-05).
 */
enum UrgentCoordination: string
{
    case Unacknowledged = 'unacknowledged';
    case Acknowledged = 'acknowledged';
    case Contacting = 'contacting';
    case Coordinating = 'coordinating';
    case Escalated = 'escalated';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Unacknowledged => 'Unacknowledged',
            self::Acknowledged => 'Acknowledged',
            self::Contacting => 'Contacting',
            self::Coordinating => 'Coordinating',
            self::Escalated => 'Escalated',
            self::Resolved => 'Resolved',
        };
    }

    /**
     * Escalation is reachable from any working state and does not end the case;
     * resolution does. Escalation recurs, so it is also an event with its own
     * record rather than a state the case is parked in (WFS 820).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Unacknowledged => [self::Acknowledged],
            self::Acknowledged => [self::Contacting, self::Escalated, self::Resolved],
            self::Contacting => [self::Coordinating, self::Escalated, self::Resolved],
            self::Coordinating => [self::Escalated, self::Resolved],
            self::Escalated => [self::Coordinating, self::Resolved],
            self::Resolved => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Acknowledging is open to every internal role: it says "I have seen this",
     * not "I have decided this". Everything afterwards is coordination work.
     *
     * @return list<Role>
     */
    public function allowedActors(self $target): array
    {
        if (!$this->canTransitionTo($target)) {
            return [];
        }

        return match ($target) {
            self::Acknowledged => [Role::Staff, Role::Coordinator, Role::Radiologist, Role::Admin],
            self::Contacting => [Role::Staff, Role::Coordinator, Role::Radiologist],
            self::Coordinating,
            self::Resolved => [Role::Coordinator, Role::Radiologist],
            self::Escalated => $this->escalationActors(),
            self::Unacknowledged => [],
        };
    }

    public function requiresReason(self $target): bool
    {
        return $target === self::Escalated;
    }

    /**
     * Whether the case may be reported resolved.
     *
     * Resolving requires that somebody owns the case; every other target is
     * governed by the transition table alone, and this method deliberately says
     * nothing about them. An earlier version claimed escalation could skip
     * acknowledgement, which contradicted that table — an unacknowledged case is
     * surfaced by the urgent fallback list instead (WF-13), not by escalating it
     * past the state nobody has picked up.
     */
    public function canProgressTo(self $target): bool
    {
        return $target !== self::Resolved || $this !== self::Unacknowledged;
    }

    /**
     * Roles that may escalate. Separate from llowedActors() because escalation
     * recurs: a case already escalated can be escalated again, and at that point
     * the transition table has no edge left to consult.
     *
     * @return list<Role>
     */
    public function escalationActors(): array
    {
        return [Role::Coordinator, Role::Radiologist];
    }

    public function isTerminal(): bool
    {
        return $this === self::Resolved;
    }

    /** WF-05 as a question a command can ask before claiming anything. */
    public function isAcknowledged(): bool
    {
        return $this !== self::Unacknowledged;
    }
}
