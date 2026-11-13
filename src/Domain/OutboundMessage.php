<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * One notification to deliver. Contains no clinical detail: a reference, a
 * time and a service name are enough for a message that may leave the system.
 */
final class OutboundMessage
{
    /**
     * @param array<string, string> $payload
     * @param string|null $dedupeKey what makes this transition unique, e.g.
     *                               "appointment:BK-260110-ABC123:moved:2026-11-12 09:00:00".
     *                               The outbox refuses a second row with the same key, so a
     *                               retried request sends once while a second move still sends.
     *                               Null falls back to the subject-and-template key the schema
     *                               used before 0021, which is right for a one-off message.
     */
    public function __construct(
        public readonly NotificationChannel $channel,
        public readonly NotificationTemplate $template,
        public readonly string $recipient,
        public readonly array $payload,
        public readonly int $id = 0,
        public readonly int $attempts = 0,
        public readonly OutboxStatus $status = OutboxStatus::Pending,
        public readonly ?string $lastError = null,
        public readonly ?string $dedupeKey = null,
        /**
         * When the provider said the message reached the recipient, as opposed to
         * when it accepted it. Null means unknown, which is not the same as "not
         * delivered": most messages never produce a receipt at all.
         */
        public readonly ?string $deliveredAt = null,
    ) {
    }

    /** The key the outbox will store this message under. */
    public function dedupeKeyFor(?int $bookingId, ?int $referralId, ?int $userId = null): string
    {
        if ($this->dedupeKey !== null) {
            return $this->dedupeKey;
        }

        // An account subject is part of the key. Without it, a message about a user
        // would fall back to "subject:0:0:…", which is the same key for every
        // account: the second person to request a reset would be silently dropped.
        // The shape for bookings and referrals is left exactly as it was, so keys
        // already written by a running system are still recognised.
        if ($userId !== null) {
            return sprintf(
                'subject:%d:%d:%d:%s:%s',
                $bookingId ?? 0,
                $referralId ?? 0,
                $userId,
                $this->template->value,
                $this->channel->value,
            );
        }

        return sprintf(
            'subject:%d:%d:%s:%s',
            $bookingId ?? 0,
            $referralId ?? 0,
            $this->template->value,
            $this->channel->value,
        );
    }

    public function withDispatch(int $id, int $attempts): self
    {
        return $this->withState(OutboxStatus::Sending, $attempts, $this->lastError, $id);
    }

    public function withState(
        OutboxStatus $status,
        int $attempts,
        ?string $lastError = null,
        ?int $id = null,
    ): self {
        return new self(
            channel: $this->channel,
            template: $this->template,
            recipient: $this->recipient,
            payload: $this->payload,
            id: $id ?? $this->id,
            attempts: $attempts,
            status: $status,
            lastError: $lastError,
            dedupeKey: $this->dedupeKey,
            deliveredAt: $this->deliveredAt,
        );
    }

    /**
     * Rendered message body.
     *
     * The zone is a parameter rather than configuration read here, because the
     * message is stored in UTC and rendered at the edge: a patient reading "09:00"
     * when the clinic means 12:00 has been given the wrong appointment. A default
     * would have made printing UTC look deliberate.
     */
    public function body(DateTimeZone $zone): string
    {
        $reference = $this->payload['reference'] ?? '';
        $when = $this->when($zone);

        return match ($this->template) {
            NotificationTemplate::BookingRequested => sprintf(
                'We received booking request %s for %s. The team will confirm shortly.',
                $reference,
                $when,
            ),
            NotificationTemplate::BookingConfirmed => sprintf(
                'Booking %s is confirmed for %s.',
                $reference,
                $when,
            ),
            NotificationTemplate::BookingCancelled => sprintf(
                'Booking %s for %s has been cancelled.',
                $reference,
                $when,
            ),
            // A referral acknowledgement says only that the request arrived. It
            // carries no clinical judgement and no appointment time, because
            // neither exists yet (WF-01, WF-11).
            NotificationTemplate::ReferralReceived => sprintf(
                'We received referral %s. The clinical team will review it and contact you.',
                $reference,
            ),
            // Appointment messages name the reference and the time and nothing
            // else: no patient name, no procedure, no clinical reason. A message
            // that may be read by whoever holds the phone must not disclose why
            // somebody is attending.
            NotificationTemplate::AppointmentBooked => sprintf(
                'Appointment %s is booked for %s.',
                $reference,
                $when,
            ),
            NotificationTemplate::AppointmentMoved => sprintf(
                'Appointment %s has been moved to %s.',
                $reference,
                $when,
            ),
            NotificationTemplate::AppointmentCancelled => sprintf(
                'Appointment %s on %s has been cancelled. Please contact the clinic to rebook.',
                $reference,
                $when,
            ),
            // The only message addressed to a member of staff rather than a patient,
            // and the only one that carries a secret. It says nothing about who the
            // account belongs to: an address that receives this message already knows
            // whose it is, and a mistaken recipient learns nothing they can use.
            NotificationTemplate::PasswordReset => sprintf(
                "Use this link to set a new password:\n\n%s\n\n"
                    . "The link can be used once and expires at %s. "
                    . 'If you did not ask for this, ignore this message; your password stays as it is.',
                $this->payload['reset_url'] ?? '',
                $this->expiresAt($zone),
            ),
        };
    }

    private function when(DateTimeZone $zone): string
    {
        $utc = $this->payload['scheduled_at_utc'] ?? '';

        if ($utc !== '') {
            $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

            if ($at !== false) {
                return $at->setTimezone($zone)->format('D j M Y, H:i T');
            }
        }

        // Older callers pre-format the time themselves; the string is printed as
        // given rather than guessed at.
        return $this->payload['scheduled_at'] ?? '';
    }

    /**
     * When a credential stops working, in the clinic's clock.
     *
     * The expiry is stored in UTC and printed in the zone the message is rendered
     * in, for the same reason an appointment time is: somebody deciding whether a
     * link is still good should not have to do arithmetic.
     */
    private function expiresAt(DateTimeZone $zone): string
    {
        $utc = $this->payload['expires_at_utc'] ?? '';

        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

        return $at === false
            ? 'the time stated in the request'
            : $at->setTimezone($zone)->format('D j M Y, H:i T');
    }
}
