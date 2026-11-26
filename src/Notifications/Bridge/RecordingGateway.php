<?php

declare(strict_types=1);

namespace App\Notifications\Bridge;

use App\Domain\NotificationTemplate;

/**
 * A gateway that records what it was asked to send and sends nothing.
 *
 * It exists so the whole path — application, outbox, dispatcher, HTTP client,
 * bridge, contract — can be exercised before an SMS provider has been chosen,
 * and so the contract can be verified by a test that does not need a phone.
 *
 * It is deliberately not called a log transport, and it is deliberately not the
 * default: an operator who points the application at a bridge running this
 * gateway gets rows marked `sent` in the outbox and no message in front of a
 * patient. That is exactly the failure the application refuses to make for
 * itself (DeliveryReadiness), so the name of this class says what it does. A
 * deployment that needs delivery implements BridgeGateway against a provider and
 * deletes this from the wiring.
 */
final class RecordingGateway implements BridgeGateway
{
    public function __construct(private readonly string $recordFile)
    {
    }

    public function deliver(array $message, string $idempotencyKey): string
    {
        // One template carries a working password-reset link. The application
        // scrubs it from the outbox the moment the message is on its way; a bridge
        // that wrote it into a file would put the same credential back on disk,
        // with a longer life and no scrubbing at all.
        $carriesCredential = NotificationTemplate::tryFrom($message['template'])?->carriesCredential() ?? false;

        $line = json_encode([
            'recorded_at' => gmdate('Y-m-d H:i:s'),
            'idempotency_key' => $idempotencyKey,
            'channel' => $message['channel'],
            'template' => $message['template'],
            'to' => $message['to'],
            'reference' => $message['reference'],
            // The body is kept because this file is the only evidence that the
            // rendering survived the round trip. It contains a reference and a
            // time, never a clinical reason — and never a credential.
            'body' => $carriesCredential ? '[redacted: this message carries a single-use link]' : $message['body'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($line === false) {
            throw new BridgeDeliveryFailed('cannot encode the record');
        }

        $written = @file_put_contents($this->recordFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

        if ($written === false) {
            throw new BridgeDeliveryFailed('cannot write to ' . $this->recordFile);
        }

        return 'rec-' . substr(hash('sha256', $line), 0, 20);
    }
}
