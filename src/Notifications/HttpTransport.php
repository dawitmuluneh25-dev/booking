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
 * Delivery here is at-least-once, and the bridge has to be able to make that
 * harmless. A dispatcher that dies between the bridge accepting a message and
 * the outbox recording the acceptance will re-claim the row after the stale
 * window and post it again — the same row, so the same `idempotency_key`. The
 * contract therefore requires the bridge to treat a repeated key as a repeat and
 * answer 2xx without sending a second time. Without that, a crash costs a patient
 * a duplicate SMS; with it, a duplicate is a no-op the bridge can prove.
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

    public function send(OutboundMessage $message): ?string
    {
        if ($this->url === '') {
            throw new RuntimeException('NOTIFY_HTTP_URL is not configured.');
        }

        $idempotencyKey = $message->id > 0 ? 'msg-' . $message->id : '';

        $payload = json_encode([
            'channel' => $message->channel->value,
            'template' => $message->template->value,
            'to' => $message->recipient,
            'body' => $message->body($this->zone ?? new DateTimeZone('UTC')),
            'reference' => $message->payload['reference'] ?? '',
            'idempotency_key' => $idempotencyKey,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw new RuntimeException('Cannot encode the notification payload.');
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'booking-app/1.0',
        ];

        // Sent as a header as well as a field: a proxy or gateway in front of the
        // bridge can dedupe on the header without parsing the body.
        if ($idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        if ($this->token !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        $response = $this->client->post($this->url, $payload, $headers, $this->timeoutSeconds);

        if (!$response->isSuccess()) {
            throw new RuntimeException('Notification gateway returned HTTP ' . $response->status . '.');
        }

        // The bridge is the provider from this application's point of view, so
        // whatever identifier it returns is what can be reconciled later.
        return $response->messageId();
    }
}
