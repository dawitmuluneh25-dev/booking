<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\OutboundMessage;
use DateTimeZone;
use RuntimeException;

/**
 * Delivers notifications to an HTTP bridge you operate in front of the chosen
 * SMS/WhatsApp gateway.
 *
 * The payload is deliberately small and free of clinical detail: the bridge
 * only needs to know where to send, what to say and which booking it belongs
 * to. Provider selection (credentials, sender id, template registration) stays
 * in the bridge, so changing provider never touches this application.
 *
 * A non-2xx answer is a failure the outbox will retry; it is never swallowed.
 *
 * The optional zone exists for tests and for a deployment that genuinely runs in
 * UTC. The application always passes the clinic zone, so a patient reading the
 * message sees the clock of the department, not the server's.
 */
final class HttpTransport implements NotificationTransport
{
    public function __construct(
        private readonly HttpClient $client,
        private readonly string $url,
        private readonly string $token = '',
        private readonly int $timeoutSeconds = 5,
        private readonly ?DateTimeZone $zone = null,
    ) {
    }

    public function send(OutboundMessage $message): void
    {
        if ($this->url === '') {
            throw new RuntimeException('NOTIFY_HTTP_URL is not configured.');
        }

        $payload = json_encode([
            'channel' => $message->channel->value,
            'template' => $message->template->value,
            'to' => $message->recipient,
            'body' => $message->body($this->zone ?? new DateTimeZone('UTC')),
            'reference' => $message->payload['reference'] ?? '',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw new RuntimeException('Cannot encode the notification payload.');
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'booking-app/1.0',
        ];

        if ($this->token !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        $status = $this->client->post($this->url, $payload, $headers, $this->timeoutSeconds);

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Notification gateway returned HTTP ' . $status . '.');
        }
    }
}
