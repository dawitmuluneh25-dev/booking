<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * A procedure-day event that cannot be recorded.
 *
 * Every message names the specific gap rather than saying "not allowed", because
 * the person reading it is standing in a room with a patient in it. Where the gap
 * is something they have to go and do, the message says what.
 */
final class ProcedureMilestoneException extends RuntimeException
{
    public static function alreadyRecorded(
        ProcedureMilestone $kind,
        string $atUtc,
        Role $role,
    ): self {
        return new self(
            $kind->label() . ' was already recorded at ' . $atUtc . ' UTC by a '
            . $role->label() . ', and nothing has been recorded since. Recording it again '
            . 'would assert an event that has not happened a second time.',
        );
    }

    public static function notNow(ProcedureMilestone $kind, AppointmentStatus $status): self
    {
        $allowed = array_map(
            static fn (AppointmentStatus $case): string => $case->label(),
            $kind->allowedFrom(),
        );

        return new self(
            $kind->label() . ' cannot be recorded while the appointment is '
            . $status->label() . '. It can be recorded from ' . self::listOf($allowed) . '.',
        );
    }

    /**
     * "Consent verified" about an appointment that has no referral behind it would
     * be a sentence about nothing, so it is refused rather than stored.
     */
    public static function noReferral(): self
    {
        return new self(
            'This appointment is not linked to a referral, so there is no consent record '
            . 'to verify. Record the consent discussion against the referral first (§18).',
        );
    }

    public static function noConsentRecord(): self
    {
        return new self(
            'No consent discussion is recorded for this referral, so there is nothing to '
            . 'verify. Record the discussion and its outcome first (§18.1); a verification '
            . 'against no record is not evidence.',
        );
    }

    public static function consentNotStanding(string $standing): self
    {
        return new self(
            'The consent currently recorded for this referral is ' . $standing
            . ', so there is no agreement to verify at the point of care. If the '
            . 'discussion has been repeated, record the new consent first.',
        );
    }

    /** @param list<string> $items */
    private static function listOf(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        return implode(', ', array_slice($items, 0, -1)) . ' or ' . $items[count($items) - 1];
    }
}
