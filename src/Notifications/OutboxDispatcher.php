<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\OutboxRepository;
use Throwable;

/**
 * CLI worker. Never invoked from a web request.
 *
 * Retries use a fixed backoff and give up after MAX_ATTEMPTS so a permanently
 * broken recipient or provider cannot loop forever.
 */
final class OutboxDispatcher
{
    public const MAX_ATTEMPTS = 5;
    public const STALE_AFTER_SECONDS = 600;

    public function __construct(
        private readonly OutboxRepository $outbox,
        private readonly NotificationTransport $transport,
    ) {
    }

    /** @return array{sent: int, retrying: int, dead: int} */
    public function dispatch(int $limit = 20): array
    {
        $messages = $this->outbox->claim(
            $limit,
            gmdate('Y-m-d H:i:s', time() - self::STALE_AFTER_SECONDS),
        );

        $result = ['sent' => 0, 'retrying' => 0, 'dead' => 0];

        foreach ($messages as $message) {
            try {
                $this->transport->send($message);
                $this->outbox->markSent($message->id);
                $result['sent']++;
            } catch (Throwable $exception) {
                $error = mb_substr($exception->getMessage(), 0, 500);

                if ($message->attempts >= self::MAX_ATTEMPTS) {
                    $this->outbox->markDead($message->id, $error);
                    $result['dead']++;
                } else {
                    $this->outbox->markFailed(
                        $message->id,
                        $error,
                        gmdate('Y-m-d H:i:s', time() + self::backoffMinutes($message->attempts) * 60),
                    );
                    $result['retrying']++;
                }
            }
        }

        return $result;
    }

    public static function backoffMinutes(int $attempts): int
    {
        return match (true) {
            $attempts <= 1 => 1,
            $attempts === 2 => 5,
            $attempts === 3 => 15,
            default => 60,
        };
    }
}
