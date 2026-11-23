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

    /**
     * The route key HR-ENG-DEC-R1-003 D-06 gives this category.
     *
     * D-06 names five keys — `PRIMARY_IR`, `SECONDARY_RAD`, `SURGICAL_TEAM`, `AFTER_HOURS`,
     * `TECHNICAL_SUPPORT` — and 0042 named the same five arrangements in the vocabulary Product
     * Owner Decision 4 used. They are the same five things, and this method is the statement of
     * that: one mapping, in the domain, tested, rather than a comment claiming the two lists agree.
     *
     * D-06 also gives each one a required receiving role, which is what `receivingRole()` returns.
     * The role is text and not a foreign key, because Operations assigns people to roles and the
     * register must be able to say "this is the post that answers" before anybody holds it.
     */
    public function routeKey(): string
    {
        return match ($this) {
            self::Primary => 'PRIMARY_IR',
            self::Secondary => 'SECONDARY_RAD',
            self::ReceivingTeam => 'SURGICAL_TEAM',
            self::AfterHours => 'AFTER_HOURS',
            self::TechnicalFallback => 'TECHNICAL_SUPPORT',
        };
    }

    /** The receiving role D-06's table requires this route to reach. */
    public function receivingRole(): string
    {
        return match ($this) {
            self::Primary => 'Primary receiving interventional radiologist',
            self::Secondary => 'Secondary radiology lead / clinical lead',
            self::ReceivingTeam => 'Receiving surgical or paediatric team',
            self::AfterHours => 'Verified on-call clinician',
            self::TechnicalFallback => 'Engineering support lead',
        };
    }

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
