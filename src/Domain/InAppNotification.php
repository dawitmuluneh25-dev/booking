<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One line in the in-app notification list: what it was about, and when.
 *
 * Nothing else, and that is the point. The outbox already holds the recipient, the
 * message body and the subject's identifiers; a list that reproduced them would
 * become a second, casually readable copy of patient contact detail, one screen
 * away from anybody who can sign in. A reference and a time are enough to tell a
 * person that something happened and where to look, and are useless to anybody who
 * is not entitled to look.
 *
 * A reference is not a name. `BK-…` and `REF-…` are handles the clinic prints on
 * paper; they say nothing about who the patient is.
 */
final class InAppNotification
{
    public function __construct(
        public readonly string $reference,
        public readonly string $occurredAt,
    ) {
    }
}
