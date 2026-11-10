<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A physician profile as the verification queue shows it.
 *
 * The profile answers "may we accept referrals from this person"; the queue has to
 * answer "who is this person", and the name and address live on the account. Joining
 * them at read time keeps the profile free of display copy that would go stale when
 * somebody corrects their address.
 */
final readonly class PhysicianQueueEntry
{
    public function __construct(
        public PhysicianProfile $profile,
        public string $name,
        public string $email,
    ) {
    }

    public function isPending(): bool
    {
        return $this->profile->status === PhysicianStatus::Pending;
    }

    /** When it was submitted, in the clinic's zone rather than the server's. */
    public function createdLine(string $timezone): string
    {
        $at = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $this->profile->createdAtUtc,
            new DateTimeZone('UTC'),
        );

        if ($at === false) {
            return $this->profile->createdAtUtc;
        }

        return $at->setTimezone(new DateTimeZone($timezone))->format('j M Y, H:i');
    }

    /** "Verified by #7 on 10 Nov 2026" — identifiers, not a second copy of the name. */
    public function reviewLine(string $timezone): string
    {
        if ($this->profile->verifiedAtUtc === null) {
            return 'Not reviewed';
        }

        $at = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $this->profile->verifiedAtUtc,
            new DateTimeZone('UTC'),
        );

        if ($at === false) {
            return 'Reviewed';
        }

        $by = $this->profile->verifiedByUserId === null
            ? 'an unknown reviewer'
            : 'user #' . $this->profile->verifiedByUserId;

        return $at->setTimezone(new DateTimeZone($timezone))->format('j M Y, H:i')
            . ' · ' . $by;
    }
}
