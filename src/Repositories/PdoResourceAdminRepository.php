<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Resource;
use App\Domain\ResourceAdminRepository;
use App\Domain\ValidationException;
use PDO;
use PDOException;

/**
 * Resource configuration against MySQL.
 *
 * The week is replaced rather than edited row by row, and the replacement is
 * scoped to `valid_from IS NULL AND valid_to IS NULL`. A dated rule — a winter
 * timetable, a three-week shutdown — is a different row set, and deleting it
 * because somebody changed Tuesday would quietly rewrite a period the clinic has
 * already published.
 */
final class PdoResourceAdminRepository implements ResourceAdminRepository
{
    private const COLUMNS = 'id, code, name, kind, modality, is_active';

    private const KINDS = ['suite', 'room', 'clinician', 'equipment'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<Resource> */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT ' . self::COLUMNS . " FROM resources
              WHERE kind IN ('suite', 'room')
              ORDER BY is_active DESC, name ASC"
        );

        if ($statement === false) {
            return [];
        }

        $resources = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $resources[] = Resource::fromRow($row);
            }
        }

        return $resources;
    }

    public function create(string $code, string $name, string $kind, ?string $modality, bool $active): Resource
    {
        $code = $this->code($code);
        $name = $this->name($name);
        $this->assertKind($kind);

        $now = gmdate('Y-m-d H:i:s');

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO resources (code, name, kind, modality, is_active, created_at, updated_at)
                 VALUES (:code, :name, :kind, :modality, :active, :created_at, :updated_at)'
            );
            $statement->execute([
                'code' => $code,
                'name' => $name,
                'kind' => $kind,
                'modality' => $modality === null || trim($modality) === '' ? null : mb_substr(trim($modality), 0, 60),
                'active' => $active ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
            // 23000 is the unique key on code. Saying so beats an unhandled 500.
            if ($exception->getCode() === '23000') {
                throw new ValidationException(['code' => 'Another room already uses that code.']);
            }

            throw $exception;
        }

        $id = (int) $this->pdo->lastInsertId();

        return new Resource($id, $code, $name, $kind, $modality, $active);
    }

    public function update(int $id, string $name, string $kind, ?string $modality, bool $active): Resource
    {
        $name = $this->name($name);
        $this->assertKind($kind);

        $existing = $this->find($id);

        if ($existing === null) {
            throw new ValidationException(['name' => 'That room no longer exists.']);
        }

        $statement = $this->pdo->prepare(
            'UPDATE resources
                SET name = :name, kind = :kind, modality = :modality, is_active = :active, updated_at = :updated_at
              WHERE id = :id'
        );
        $statement->execute([
            'name' => $name,
            'kind' => $kind,
            'modality' => $modality === null || trim($modality) === '' ? null : mb_substr(trim($modality), 0, 60),
            'active' => $active ? 1 : 0,
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'id' => $id,
        ]);

        return new Resource($id, $existing->code, $name, $kind, $modality, $active);
    }

    /** @return array<int, array{opens: string, closes: string}|null> */
    public function defaultWeek(int $resourceId): array
    {
        $week = array_fill(1, 7, null);

        $statement = $this->pdo->prepare(
            'SELECT weekday, opens_at, closes_at
               FROM resource_availability
              WHERE resource_id = :resource_id
                AND valid_from IS NULL
                AND valid_to IS NULL
              ORDER BY weekday, opens_at'
        );
        $statement->execute(['resource_id' => $resourceId]);

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row) || !is_numeric($row['weekday'] ?? null)) {
                continue;
            }

            $weekday = (int) $row['weekday'];

            // array_key_exists, not isset: the week is filled with nulls to mean
            // the shape 1-7, and isset() reports a null value as absent.
            if (!array_key_exists($weekday, $week)) {
                continue;
            }

            $opens = is_scalar($row['opens_at'] ?? null) ? (string) $row['opens_at'] : '';
            $closes = is_scalar($row['closes_at'] ?? null) ? (string) $row['closes_at'] : '';

            // One window per weekday here: the editor offers a single open/close
            // pair, and a second row on the same day would be lost on the next
            // save. Splitting a day is a deliberate act that this screen does not
            // pretend to support.
            if ($week[$weekday] === null) {
                $week[$weekday] = ['opens' => $opens, 'closes' => $closes];
            }
        }

        return $week;
    }

    /** @param array<int, array{opens: string, closes: string}|null> $week */
    public function replaceDefaultWeek(int $resourceId, array $week): void
    {
        $rows = [];

        foreach ($week as $weekday => $window) {
            if ($window === null) {
                continue;
            }

            $opens = $this->clock($window['opens'], 'opens');
            $closes = $this->clock($window['closes'], 'closes');

            if ($opens >= $closes) {
                throw new ValidationException([
                    'week' => 'Day ' . $weekday . ': the closing time has to be after the opening time.',
                ]);
            }

            $rows[] = ['weekday' => $weekday, 'opens' => $opens, 'closes' => $closes];
        }

        $delete = $this->pdo->prepare(
            'DELETE FROM resource_availability
              WHERE resource_id = :resource_id AND valid_from IS NULL AND valid_to IS NULL'
        );
        $delete->execute(['resource_id' => $resourceId]);

        if ($rows === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO resource_availability (resource_id, weekday, opens_at, closes_at, valid_from, valid_to)
             VALUES (:resource_id, :weekday, :opens_at, :closes_at, NULL, NULL)'
        );

        foreach ($rows as $row) {
            $insert->execute([
                'resource_id' => $resourceId,
                'weekday' => $row['weekday'],
                'opens_at' => $row['opens'],
                'closes_at' => $row['closes'],
            ]);
        }
    }

    private function find(int $id): ?Resource
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM resources WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return Resource::fromRow($row);
    }

    private function code(string $code): string
    {
        $code = mb_strtolower(trim($code));

        if (preg_match('/\A[a-z0-9][a-z0-9-]{1,39}\z/', $code) !== 1) {
            throw new ValidationException([
                'code' => 'Use 2-40 characters: lower-case letters, digits and hyphens.',
            ]);
        }

        return $code;
    }

    private function name(string $name): string
    {
        $name = trim($name);

        if (mb_strlen($name) < 2) {
            throw new ValidationException(['name' => 'Give the room a name people will recognise.']);
        }

        if (mb_strlen($name) > 120) {
            throw new ValidationException(['name' => 'The name cannot be longer than 120 characters.']);
        }

        return $name;
    }

    private function assertKind(string $kind): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new ValidationException(['kind' => 'Choose a room or a suite.']);
        }
    }

    private function clock(string $value, string $field): string
    {
        $value = trim($value);

        if (preg_match('/\A[0-9]{2}:[0-9]{2}\z/', $value) !== 1) {
            throw new ValidationException(['week' => 'Times are written as HH:MM.']);
        }

        return $value . ':00';
    }
}
