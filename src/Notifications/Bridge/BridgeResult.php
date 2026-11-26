<?php

declare(strict_types=1);

namespace App\Notifications\Bridge;

/**
 * What the bridge answers, as a status and a small JSON body.
 *
 * The body always says what happened in words a log can hold: an operator
 * reading the application's outbox after a failure sees the recorded message,
 * not an HTTP code they have to look up.
 */
final class BridgeResult
{
    /** @param array<string, string|int|bool> $body */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
    ) {
    }

    public static function error(int $status, string $message): self
    {
        return new self($status, ['error' => $message]);
    }

    public static function accepted(string $messageId, bool $duplicate): self
    {
        return new self($duplicate ? 200 : 202, [
            'status' => $duplicate ? 'duplicate' : 'accepted',
            'messageId' => $messageId,
        ]);
    }

    public function json(): string
    {
        $encoded = json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '{"error":"cannot encode response"}' : $encoded;
    }
}
