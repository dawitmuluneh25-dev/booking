<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\AuditRepository;
use App\Domain\BookingEvent;
use PDO;

final class PdoAuditRepository implements AuditRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function recent(int $limit, string $reference = ''): array
    {
        $sql = 'SELECT e.id, b.reference, e.from_status, e.to_status, e.actor, e.reason, e.created_at
                FROM booking_events AS e
                JOIN bookings AS b ON b.id = e.booking_id';

        if ($reference !== '') {
            $sql .= ' WHERE b.reference = :reference';
        }

        $sql .= ' ORDER BY e.id DESC LIMIT :limit';

        $statement = $this->pdo->prepare($sql);
        if ($reference !== '') {
            $statement->bindValue('reference', $reference);
        }
        $statement->bindValue('limit', max(1, min($limit, 200)), PDO::PARAM_INT);
        $statement->execute();

        $events = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $events[] = BookingEvent::fromRow($row);
            }
        }

        return $events;
    }

    public function count(string $reference = ''): int
    {
        if ($reference === '') {
            $statement = $this->pdo->query('SELECT COUNT(*) FROM booking_events');

            return $statement === false ? 0 : (int) $statement->fetchColumn();
        }

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM booking_events AS e
             JOIN bookings AS b ON b.id = e.booking_id
             WHERE b.reference = :reference'
        );
        $statement->execute(['reference' => $reference]);

        return (int) $statement->fetchColumn();
    }
}
