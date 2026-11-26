<?php

declare(strict_types=1);

namespace App\Notifications\Bridge;

/**
 * The receiving end of the `http` notification driver.
 *
 * This is a reference implementation of the contract in
 * docs/operations/notification-bridge-contract.md: it authenticates the caller,
 * validates what arrived, refuses to deliver the same outbox row twice, and
 * answers in the shape the application parses. The provider-specific part is
 * delegated to a BridgeGateway so that changing provider is a class, not a
 * protocol change.
 *
 * Two rules it inherits from the application and does not soften:
 *
 *  - It never answers 2xx for something it did not accept. The application marks
 *    an outbox row `sent` on 2xx, and that row is the record staff use to answer
 *    "was the patient told?". A cheerful answer here would be a lie the database
 *    repeats.
 *  - It treats the message body as opaque text. Nothing in the payload carries a
 *    diagnosis and the bridge must not add one by interpreting it.
 */
final class NotificationBridge
{
    public const MAX_BODY_BYTES = 8192;

    public const CHANNELS = ['sms', 'whatsapp', 'email'];

    public function __construct(
        private readonly BridgeGateway $gateway,
        private readonly string $token,
        private readonly string $stateDirectory,
    ) {
    }

    /**
     * @param array<string, string> $headers header names as received; matching is
     *                                       case-insensitive because servers disagree
     */
    public function handle(array $headers, string $rawBody): BridgeResult
    {
        // An unset token is a misconfiguration, not an open door. Refusing is the
        // only reading that does not turn a forgotten environment variable into an
        // unauthenticated endpoint that sends SMS on somebody's bill.
        if ($this->token === '') {
            return BridgeResult::error(503, 'bridge token is not configured; refusing every request');
        }

        if (!$this->authorised($headers)) {
            return BridgeResult::error(401, 'unauthorized');
        }

        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return BridgeResult::error(413, 'payload exceeds ' . self::MAX_BODY_BYTES . ' bytes');
        }

        $decoded = json_decode($rawBody, true);

        if (!is_array($decoded)) {
            return BridgeResult::error(400, 'body is not a JSON object');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $channel = $this->field($data, 'channel');
        $template = $this->field($data, 'template');
        $to = $this->field($data, 'to');
        $body = $this->field($data, 'body');
        $reference = $this->field($data, 'reference') ?? '';

        if ($channel === null || $template === null || $to === null || $body === null) {
            return BridgeResult::error(400, 'channel, template, to and body are required strings');
        }

        if (!in_array($channel, self::CHANNELS, true)) {
            return BridgeResult::error(422, 'unknown channel: ' . $channel);
        }

        $recipientProblem = $this->recipientProblem($channel, $to);

        if ($recipientProblem !== null) {
            return BridgeResult::error(422, $recipientProblem);
        }

        $key = $this->field($data, 'idempotency_key') ?? $this->header($headers, 'idempotency-key') ?? '';

        if ($key !== '' && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $key) !== 1) {
            return BridgeResult::error(400, 'idempotency key is not a token of up to 128 characters');
        }

        $message = [
            'channel' => $channel,
            'template' => $template,
            'to' => $to,
            'body' => $body,
            'reference' => $reference,
        ];

        return $key === ''
            ? $this->deliverOnce($message, '')
            : $this->deliverAtMostOnce($message, $key);
    }

    /**
     * @param array{channel: string, template: string, to: string, body: string, reference: string} $message
     */
    private function deliverOnce(array $message, string $key): BridgeResult
    {
        try {
            $providerId = $this->gateway->deliver($message, $key);
        } catch (BridgeDeliveryFailed $failure) {
            return BridgeResult::error(502, mb_substr($failure->getMessage(), 0, 300));
        }

        return BridgeResult::accepted($providerId === '' ? $this->fallbackId($key) : $providerId, false);
    }

    /**
     * One delivery per idempotency key, even when two dispatchers race.
     *
     * The lock is held for the whole delivery, so the second caller waits rather
     * than reading an empty record and sending again. A repeat answers with the
     * identifier the first send recorded: the application is told "this was
     * already accepted" instead of being handed a second message id it would
     * store against a message it never duplicated.
     *
     * @param array{channel: string, template: string, to: string, body: string, reference: string} $message
     */
    private function deliverAtMostOnce(array $message, string $key): BridgeResult
    {
        $directory = rtrim($this->stateDirectory, '/\\');

        if ($directory === '') {
            return BridgeResult::error(503, 'bridge state directory is not configured');
        }

        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            return BridgeResult::error(503, 'bridge cannot create its state directory');
        }

        $stem = $directory . DIRECTORY_SEPARATOR . hash('sha256', $key);
        $lock = @fopen($stem . '.lock', 'c');

        if ($lock === false) {
            return BridgeResult::error(503, 'bridge cannot open its state directory');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                return BridgeResult::error(503, 'bridge cannot lock its state directory');
            }

            $record = $stem . '.json';

            if (is_file($record)) {
                return BridgeResult::accepted($this->recordedId($record, $key), true);
            }

            $result = $this->deliverOnce($message, $key);

            if ($result->status === 202) {
                $id = $result->body['messageId'] ?? $this->fallbackId($key);
                @file_put_contents($record, (string) json_encode([
                    'messageId' => $id,
                    'key' => $key,
                    'accepted_at' => gmdate('Y-m-d H:i:s'),
                ]));
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function recordedId(string $record, string $key): string
    {
        $contents = @file_get_contents($record);
        $decoded = is_string($contents) ? json_decode($contents, true) : null;

        if (is_array($decoded) && isset($decoded['messageId']) && is_string($decoded['messageId'])) {
            return $decoded['messageId'];
        }

        return $this->fallbackId($key);
    }

    /**
     * A stable identifier for a message the provider did not name.
     *
     * Derived from the key rather than random so that the same row always answers
     * with the same id: an outbox entry that changed identity between a send and
     * a duplicate would look like two messages in any reconciliation.
     */
    private function fallbackId(string $key): string
    {
        return $key === ''
            ? 'br-' . bin2hex(random_bytes(8))
            : 'br-' . substr(hash('sha256', $key), 0, 20);
    }

    private function recipientProblem(string $channel, string $to): ?string
    {
        if ($channel === 'email') {
            return filter_var($to, FILTER_VALIDATE_EMAIL) === false
                ? 'recipient is not a valid email address'
                : null;
        }

        return preg_match('/^\+[1-9][0-9]{6,14}$/', $to) === 1
            ? null
            : 'recipient must be E.164 with a leading + for ' . $channel;
    }

    /** @param array<string, string> $headers */
    private function authorised(array $headers): bool
    {
        $presented = $this->header($headers, 'authorization');

        return $presented !== null && hash_equals('Bearer ' . $this->token, $presented);
    }

    /** @param array<string, string> $headers */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $name) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function field(array $data, string $name): ?string
    {
        $value = $data[$name] ?? null;

        return is_string($value) ? $value : null;
    }
}
