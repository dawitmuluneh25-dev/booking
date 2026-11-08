<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One attempt to reach somebody about an urgent case.
 *
 * WF-06 asks for the attempt to be recorded, not summarised: who tried, which role
 * they were trying to reach, on what number, by which channel, and what happened.
 * "Called the family" tells the next shift nothing; "19:40, telephone, guardian,
 * +2519…, no answer" tells them not to try the same number again.
 */
final class UrgentContactAttempt
{
    public const ROLES = ['referring_clinician', 'hospital', 'patient_family', 'receiving_team', 'other'];

    public const CHANNELS = ['telephone', 'application', 'in_person', 'other'];

    public const OUTCOMES = [
        'answered',
        'no_answer',
        'busy',
        'wrong_number',
        'message_left',
        'connected',
        'failed',
        'other',
    ];

    /** Outcomes that mean a person was actually reached. */
    public const CONNECTED = ['answered', 'connected'];

    public function __construct(
        public readonly int $caseId,
        public readonly int $attemptedByUserId,
        public readonly string $contactRole,
        public readonly ?string $contactName,
        public readonly ?string $contactPhone,
        public readonly string $channel,
        public readonly string $outcome,
        public readonly ?string $notes,
        public readonly string $attemptedAtUtc,
    ) {
    }

    public function reachedSomeone(): bool
    {
        return in_array($this->outcome, self::CONNECTED, true);
    }
}
