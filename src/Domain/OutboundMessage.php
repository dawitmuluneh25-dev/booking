<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One notification to deliver. Contains no clinical detail: a reference, a
 * time and a service name are enough for a message that may leave the system.
 */
final class OutboundMessage
{
    /**
     * @param array<string, string> $payload
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
    ) {
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
        );
    }

    /** Rendered message body. Kept generic on purpose. */
    public function body(): string
    {
        $reference = $this->payload['reference'] ?? '';
        $when = $this->payload['scheduled_at'] ?? '';

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
        };
    }
}
