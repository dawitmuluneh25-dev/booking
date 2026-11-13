<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\OutboundMessage;
use PDO;

/**
 * Writes notification rows inside whatever transaction the caller already opened.
 *
 * It takes no transaction of its own on purpose: a message that commits separately
 * from the change it describes is either a notification for something that did not
 * happen or a change nobody was told about (WF-14). The repository methods that
 * use it are already inside a unit of work, and this class must stay callable only
 * from there.
 *
 * `INSERT IGNORE` is the idempotency guard. A retried request produces the same
 * dedupe key and inserts nothing; a genuinely new transition produces a new key
 * and inserts once.
 *
 * A message names exactly one subject — a booking, a referral or an account. The
 * database refuses a row that names none (ck_outbox_subject), so there is no path
 * through here that writes an unattached message by accident.
 */
final class OutboxWriter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<OutboundMessage> $messages
     */
    public function write(?int $bookingId, ?int $referralId, array $messages): void
    {
        $this->insert($bookingId, $referralId, null, $messages);
    }

    /**
     * A message about an account rather than an appointment: a password reset is
     * the only one so far, and it carries no booking because there is none.
     *
     * @param list<OutboundMessage> $messages
     */
    public function writeForUser(int $userId, array $messages): void
    {
        $this->insert(null, null, $userId, $messages);
    }

    /**
     * @param list<OutboundMessage> $messages
     */
    private function insert(?int $bookingId, ?int $referralId, ?int $userId, array $messages): void
    {
        if ($messages === []) {
            return;
        }

        $statement = $this->pdo->prepare(
            "INSERT IGNORE INTO notification_outbox
                (booking_id, referral_id, user_id, dedupe_key, channel, template, recipient, payload,
                 status, attempts, available_at, created_at, updated_at)
             VALUES
                (:booking_id, :referral_id, :user_id, :dedupe_key, :channel, :template, :recipient, :payload,
                 'pending', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );

        foreach ($messages as $message) {
            $payload = json_encode($message->payload, JSON_UNESCAPED_SLASHES);

            $statement->execute([
                'booking_id' => $bookingId,
                'referral_id' => $referralId,
                'user_id' => $userId,
                'dedupe_key' => $message->dedupeKeyFor($bookingId, $referralId, $userId),
                'channel' => $message->channel->value,
                'template' => $message->template->value,
                'recipient' => $message->recipient,
                'payload' => $payload === false ? '{}' : $payload,
            ]);
        }
    }
}
