<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\NotificationChannel;
use App\Domain\OutboundMessage;
use DateTimeZone;
use RuntimeException;

/**
 * Transactional email through Brevo's HTTPS API (O-3-DEC-01).
 *
 * Three things here are deliberate.
 *
 * The subject is built from the message template and the reference only. An
 * email subject is the one line that survives forwarding, notification previews
 * and unread lists, so it must not say why anybody is attending.
 *
 * Only the provider's error code is kept when a send is rejected. Brevo's error
 * bodies can quote the recipient address back, and the outbox persists failures
 * into a column that operators read — copying that text in would move a patient's
 * address into a diagnostic record for no gain.
 *
 * A 4xx is permanent and a 5xx or a network error is transient. Retrying a
 * rejected payload five times does not deliver it; it only delays the point at
 * which somebody notices.
 */
final class BrevoTransport implements NotificationTransport
{
    public const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    public function __construct(
        private readonly HttpClient $client,
        private readonly string $apiKey,
        private readonly string $senderEmail,
        private readonly string $senderName = 'HakimeRAD',
        private readonly string $endpoint = self::ENDPOINT,
        private readonly int $timeoutSeconds = 10,
        private readonly ?DateTimeZone $zone = null,
        private readonly bool $activated = true,
    ) {
    }

    public function send(OutboundMessage $message): ?string
    {
        if ($message->channel !== NotificationChannel::Email) {
            throw new PermanentNotificationFailure(sprintf(
                'The email transport cannot deliver a %s message.',
                $message->channel->value,
            ));
        }

        if (!$this->activated) {
            throw new PermanentNotificationFailure(
                'Transactional email is not activated in this environment.'
            );
        }

        if ($this->apiKey === '') {
            throw new PermanentNotificationFailure('Transactional email credential is not configured.');
        }

        if (filter_var($this->senderEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new PermanentNotificationFailure('Transactional email sender is not a valid address.');
        }

        $payload = json_encode($this->payload($message), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw new PermanentNotificationFailure('Cannot encode the email payload.');
        }

        $response = $this->client->post(
            $this->endpoint,
            $payload,
            [
                'api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'booking-app/1.0',
            ],
            $this->timeoutSeconds,
        );

        if ($response->status >= 400 && $response->status < 500) {
            throw new PermanentNotificationFailure(sprintf(
                'The email provider rejected the message (HTTP %d, %s).',
                $response->status,
                self::errorCode($response),
            ));
        }

        if (!$response->isSuccess()) {
            throw new RuntimeException(sprintf(
                'The email provider returned HTTP %d.',
                $response->status,
            ));
        }

        return $response->messageId();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(OutboundMessage $message): array
    {
        return [
            'sender' => [
                'email' => $this->senderEmail,
                'name' => $this->senderName,
            ],
            'to' => [
                ['email' => $message->recipient],
            ],
            'subject' => $this->subject($message),
            'textContent' => $message->body($this->zone ?? new DateTimeZone('UTC')),
        ];
    }

    /**
     * A subject that names the message and no more.
     *
     * The reference is already the patient's handle on their own request and is
     * printed in the body, so it leaks nothing new; the procedure, the indication
     * and the patient's name never appear here.
     */
    private function subject(OutboundMessage $message): string
    {
        $reference = $message->payload['reference'] ?? '';

        return $reference === ''
            ? 'HakimeRAD notification'
            : sprintf('HakimeRAD notification %s', $reference);
    }

    /**
     * Brevo's machine code, or a placeholder. Never the human message: that is
     * the field most likely to quote the rejected recipient back at us.
     */
    private static function errorCode(HttpResponse $response): string
    {
        $decoded = json_decode($response->body, true);

        if (is_array($decoded)) {
            $code = $decoded['code'] ?? null;

            if (
                is_string($code) && $code !== '' && mb_strlen($code) <= 64
                && preg_match('/^[A-Za-z0-9_.-]+$/', $code) === 1
            ) {
                return $code;
            }
        }

        return 'rejected';
    }
}
