<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * What an HTTP call returned: enough to judge success and, for a provider that
 * hands back an identifier, enough to record it.
 *
 * The status alone was sufficient while the only transport was a bridge whose
 * answer carried no meaning. A transactional provider does return something
 * worth keeping — Brevo answers with a messageId — and "we sent it" without a
 * provider identifier cannot be reconciled against a delivery report later.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * The provider's own identifier for the message, when it supplies one.
     *
     * Both spellings are accepted because providers disagree about case and the
     * difference is not worth a second code path: Brevo answers `messageId`.
     */
    public function messageId(): ?string
    {
        if ($this->body === '') {
            return null;
        }

        $decoded = json_decode($this->body, true);

        if (!is_array($decoded)) {
            return null;
        }

        foreach (['messageId', 'message_id'] as $key) {
            $value = $decoded[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
