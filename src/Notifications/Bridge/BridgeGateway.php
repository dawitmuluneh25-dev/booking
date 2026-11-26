<?php

declare(strict_types=1);

namespace App\Notifications\Bridge;

/**
 * The only part of a bridge that knows which provider sends the message.
 *
 * Everything else — authentication, validation, de-duplication, the shape of the
 * answer — is the same whichever gateway sits behind it, so it lives in
 * NotificationBridge and is tested once. Swapping an SMS aggregator for another
 * is then an implementation of this interface, not a change to the contract the
 * booking application posts to.
 */
interface BridgeGateway
{
    /**
     * Hand an accepted message to the provider.
     *
     * @param array{channel: string, template: string, to: string, body: string, reference: string} $message
     * @param string $idempotencyKey stable per outbox row; pass it to the provider
     *                               when the provider supports its own idempotency,
     *                               so a bridge restart cannot duplicate a send
     *
     * @return string the provider's identifier for this message, or '' when the
     *                provider does not return one
     *
     * @throws BridgeDeliveryFailed when the provider refused or could not be reached
     */
    public function deliver(array $message, string $idempotencyKey): string;
}
