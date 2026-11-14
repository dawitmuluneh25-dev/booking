<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The five routes Product Owner Decision 4 names, in the order a caller uses them.
 *
 * Three are required before urgent escalation may be used at all, one exists only
 * where cover genuinely exists, and one is a fallback that never counts as a
 * clinical acknowledgement. Which is which is a property of the case, not a
 * constant in a screen, because the activation gate and the board's explanation have
 * to give the same answer.
 */
enum EscalationRouteKind: string
{
    case Primary = 'primary';
    case Secondary = 'secondary';
    case ReceivingTeam = 'receiving_team';
    case AfterHours = 'after_hours';
    case TechnicalFallback = 'technical_fallback';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Primary contact',
            self::Secondary => 'Secondary escalation',
            self::ReceivingTeam => 'Receiving surgical team',
            self::AfterHours => 'After-hours escalation',
            self::TechnicalFallback => 'Technical fallback',
        };
    }

    /**
     * Whether a verified route of this kind has to exist before escalation may be
     * used.
     *
     * Decision 4 lists primary, secondary and the receiving surgical team without a
     * qualifier and qualifies only after-hours ("where generally available only").
     * The technical fallback is excluded on the decision's own terms: it is never a
     * substitute for a clinical acknowledgement, so its presence cannot be what makes
     * acknowledgement possible.
     */
    public function requiredForActivation(): bool
    {
        return match ($this) {
            self::Primary, self::Secondary, self::ReceivingTeam => true,
            self::AfterHours, self::TechnicalFallback => false,
        };
    }

    /**
     * Whether this kind may only exist when it is genuinely staffed.
     *
     * After-hours escalation qualifies itself in the decision, so a 24-hour number
     * that nobody answers would be a false promise in the roster. The other kinds are
     * staffed by the people working the clinic day.
     */
    public function requiresStaffing(): bool
    {
        return $this === self::AfterHours;
    }
}
