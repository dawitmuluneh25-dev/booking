<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\AuditEntry;
use App\Domain\AuditEvent;
use App\Domain\AuditQuery;
use App\Domain\AuditTrail;
use JsonException;
use PDO;
use PDOStatement;

/**
 * Append-only writer and reader for `audit_events`.
 *
 * There is no `update()` or `delete()` here, and there is not meant to be: a
 * correction is another row. The reader joins `users` only to show an actor's
 * email; the stored row keeps the id, so a later rename does not rewrite history.
 */
final class PdoAuditTrail implements AuditTrail
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $requestId = '',
    ) {
    }

    /**
     * @throws JsonException when a before/after map cannot be encoded, which means
     *                       a caller passed something that is not a scalar
     */
    public function record(AuditEntry $entry): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_events
                (actor_user_id, actor_role, action, object_type, object_ref,
                 before_json, after_json, request_id, occurred_at)
             VALUES
                (:actor_user_id, :actor_role, :action, :object_type, :object_ref,
                 :before_json, :after_json, :request_id, :occurred_at)'
        );

        $statement->bindValue('actor_user_id', $entry->actorUserId, $entry->actorUserId === null
            ? PDO::PARAM_NULL
            : PDO::PARAM_INT);
        $this->bindNullable($statement, 'actor_role', $entry->actorRole?->value);
        $statement->bindValue('action', $entry->action->value);
        $statement->bindValue('object_type', $entry->objectType);
        $statement->bindValue('object_ref', $entry->objectRef);
        $this->bindNullable($statement, 'before_json', $this->encode($entry->before));
        $this->bindNullable($statement, 'after_json', $this->encode($entry->after));
        $this->bindNullable($statement, 'request_id', $this->truncate($entry->requestId ?? $this->requestId, 16));
        $statement->bindValue(
            'occurred_at',
            $entry->occurredAtUtc === '' ? gmdate('Y-m-d H:i:s') : $entry->occurredAtUtc,
        );
        $statement->execute();
    }

    public function search(AuditQuery $query): array
    {
        $query = $query->withLimit($query->limit);
        $conditions = $this->conditions($query);

        $sql = 'SELECT a.id, a.actor_user_id, a.actor_role, a.action, a.object_type, a.object_ref,
                       a.before_json, a.after_json, a.request_id, a.occurred_at,
                       u.email AS actor_email
                FROM audit_events AS a
                LEFT JOIN users AS u ON u.id = a.actor_user_id'
            . $conditions['sql']
            . ' ORDER BY a.id DESC LIMIT :limit';

        $statement = $this->pdo->prepare($sql);

        foreach ($conditions['params'] as $name => $value) {
            $statement->bindValue($name, $value);
        }

        $statement->bindValue('limit', $query->limit, PDO::PARAM_INT);
        $statement->execute();

        $events = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $events[] = AuditEvent::fromRow($row);
            }
        }

        return $events;
    }

    public function count(AuditQuery $query): int
    {
        $conditions = $this->conditions($query);

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM audit_events AS a
             LEFT JOIN users AS u ON u.id = a.actor_user_id'
            . $conditions['sql']
        );

        foreach ($conditions['params'] as $name => $value) {
            $statement->bindValue($name, $value);
        }

        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array{sql: string, params: array<string, string>}
     */
    private function conditions(AuditQuery $query): array
    {
        $clauses = [];
        $params = [];

        if ($query->action !== '') {
            $clauses[] = 'a.action = :action';
            $params['action'] = $query->action;
        }

        if ($query->objectType !== '') {
            $clauses[] = 'a.object_type = :object_type';
            $params['object_type'] = $query->objectType;
        }

        if ($query->objectRef !== '') {
            $clauses[] = 'a.object_ref = :object_ref';
            $params['object_ref'] = $query->objectRef;
        }

        if ($query->fromUtc !== '') {
            $clauses[] = 'a.occurred_at >= :from_utc';
            $params['from_utc'] = $query->fromUtc;
        }

        if ($query->toUtc !== '') {
            $clauses[] = 'a.occurred_at < :to_utc';
            $params['to_utc'] = $query->toUtc;
        }

        if ($query->actor !== '') {
            // An email fragment is the useful filter; a numeric value also matches
            // the stored id, which is what an operator has when reading a log line.
            $params['actor_like'] = '%' . $query->actor . '%';

            if (ctype_digit($query->actor)) {
                $clauses[] = '(u.email LIKE :actor_like OR a.actor_user_id = :actor_id)';
                $params['actor_id'] = $query->actor;
            } else {
                $clauses[] = 'u.email LIKE :actor_like';
            }
        }

        return [
            'sql' => $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses),
            'params' => $params,
        ];
    }

    /**
     * Binds a string or an explicit NULL.
     *
     * `bindValue($name, null)` defaults to PARAM_STR, which sends an empty string
     * on some drivers; a JSON column rejects that with "Invalid JSON text", so the
     * null case has to be stated rather than implied.
     */
    private function bindNullable(PDOStatement $statement, string $name, ?string $value): void
    {
        $statement->bindValue($name, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    }

    /** @param array<string, scalar|null>|null $values */
    private function encode(?array $values): ?string
    {
        if ($values === null || $values === []) {
            return null;
        }

        return json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function truncate(?string $value, int $length): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
