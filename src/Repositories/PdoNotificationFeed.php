<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\InAppNotification;
use App\Domain\NotificationFeedRepository;
use App\Domain\NotificationTemplate;
use PDO;
use ValueError;

/**
 * The notification list, projected from the outbox rather than stored again.
 *
 * Three columns are read and the rest are not. The recipient is deliberately left
 * in the table: it is an email address or a phone number, and a list of them on a
 * page would be a contact book for anybody who signs in.
 *
 * The time is when the message was sent if it was sent, and when it was queued if
 * it was not. A message stuck in the queue shows the moment somebody asked for it,
 * which is the fact the list is about.
 */
final class PdoNotificationFeed implements NotificationFeedRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<InAppNotification> */
    public function recent(int $limit): array
    {
        $limit = max(1, min($limit, 200));

        $statement = $this->pdo->prepare(
            'SELECT template, payload, sent_at, created_at
             FROM notification_outbox
             ORDER BY id DESC
             LIMIT :limit'
        );
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        $notifications = [];

        foreach ($rows as $row) {
            $notifications[] = new InAppNotification(
                reference: self::reference($row),
                occurredAt: self::text($row, 'sent_at') !== ''
                    ? self::text($row, 'sent_at')
                    : self::text($row, 'created_at'),
            );
        }

        return $notifications;
    }

    /**
     * The reference the message is about, or the name of the message when it is
     * about no reference at all.
     *
     * A password reset concerns an account, and this system gives accounts no
     * printed reference. Showing the template's own name is honest; showing the
     * email address would put one on a screen for no reason.
     *
     * @param array<string, mixed> $row
     */
    private static function reference(array $row): string
    {
        $payload = $row['payload'] ?? null;
        $decoded = is_string($payload) ? json_decode($payload, true) : null;

        if (is_array($decoded)) {
            $reference = $decoded['reference'] ?? null;

            if (is_string($reference) && $reference !== '') {
                return $reference;
            }
        }

        $template = self::text($row, 'template');

        try {
            return NotificationTemplate::from($template)->label();
        } catch (ValueError) {
            // A template this build no longer knows about is still a message that
            // went out. Naming it "Notification" is less useful than the raw value
            // and less wrong than dropping the row from the list.
            return $template === '' ? 'Notification' : $template;
        }
    }

    /** @param array<string, mixed> $row */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
