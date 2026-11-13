<?php

declare(strict_types=1);

namespace App\Domain;

use App\Security\Auth;

/**
 * Queue a password reset link for an address, or nothing at all.
 *
 * The caller learns nothing. An address with no account, an account that is
 * disabled, and an account that may not sign in all produce the same outcome:
 * no message, no error, the same page. Anything else would let an unauthenticated
 * visitor discover who works here by typing addresses into a public form.
 *
 * The message is queued rather than sent. A provider that is slow, down or
 * misconfigured must not decide whether the reset form succeeds, and the outbox is
 * already the place where a message waits, retries and eventually dead-letters
 * where somebody can see it.
 *
 * What this deliberately does not do is throttle. A public endpoint that queues one
 * email per request can be used to fill somebody's inbox, and the fix — how many
 * requests, over how long — is an operational decision, not something to invent
 * here. It is recorded as an open risk against this slice.
 */
final class RequestPasswordReset
{
    public function __construct(
        private readonly Auth $auth,
        private readonly OutboxRepository $outbox,
        private readonly string $baseUrl,
    ) {
    }

    public function execute(string $email): void
    {
        $link = $this->auth->issueResetToken($email);

        if ($link === null) {
            return;
        }

        $this->outbox->recordForUser($link->userId, [
            new OutboundMessage(
                channel: NotificationChannel::Email,
                template: NotificationTemplate::PasswordReset,
                recipient: strtolower(trim($email)),
                payload: [
                    // No `reference`: an account has no reference in this system, and
                    // inventing one would put a number in the notification list that
                    // leads nowhere. The list falls back to the template label.
                    'reset_url' => $this->baseUrl . '/password/reset/' . $link->token,
                    'expires_at_utc' => $link->expiresAtUtc,
                ],
                // One row per issued token. Keying on the account alone would let a
                // second request collide with the first and be dropped, leaving the
                // person waiting for a link that was never queued.
                dedupeKey: sprintf(
                    'password-reset:%d:%s',
                    $link->userId,
                    substr(hash('sha256', $link->token), 0, 16),
                ),
            ),
        ]);
    }
}
