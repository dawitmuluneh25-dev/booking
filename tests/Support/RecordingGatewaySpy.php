<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Notifications\Bridge\BridgeDeliveryFailed;
use App\Notifications\Bridge\BridgeGateway;

/**
 * A gateway that remembers what it was asked to deliver.
 *
 * The seam exists so a test can count deliveries without a provider, a phone or
 * a network: "one message, not two" is the property that matters about a retried
 * outbox row, and it is invisible from the bridge's HTTP answer alone.
 */
final class RecordingGatewaySpy implements BridgeGateway
{
    /** @var list<array{channel: string, template: string, to: string, body: string, reference: string}> */
    public array $delivered = [];

    /** @var list<string> every call, including the ones that failed */
    public array $attempts = [];

    public ?string $failure = null;

    public function __construct(private readonly string $messageId = 'provider-1')
    {
    }

    public function failWith(string $message): void
    {
        $this->failure = $message;
    }

    public function deliver(array $message, string $idempotencyKey): string
    {
        $this->attempts[] = $idempotencyKey;

        if ($this->failure !== null) {
            throw new BridgeDeliveryFailed($this->failure);
        }

        $this->delivered[] = $message;

        return $this->messageId;
    }
}
