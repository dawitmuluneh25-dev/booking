<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\ClinicCalendar;
use App\Domain\OpeningHours;
use DateTimeImmutable;
use PDO;
use Throwable;

final class PdoClinicCalendar implements ClinicCalendar
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, OpeningHours> */
    public function week(): array
    {
        $statement = $this->pdo->query(
            'SELECT weekday, closed, opens, closes FROM clinic_hours ORDER BY weekday'
        );

        $week = [];
        if ($statement === false) {
            return $week;
        }

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $hours = self::hydrate($row);
            if ($hours !== null) {
                $week[$hours->weekday] = $hours;
            }
        }

        return $week;
    }

    public function hoursFor(DateTimeImmutable $localDate): ?OpeningHours
    {
        $weekday = (int) $localDate->format('N');
        $hours = $this->week()[$weekday] ?? null;

        if ($hours === null || $hours->closed) {
            return null;
        }

        $closure = $this->pdo->prepare(
            'SELECT 1 FROM clinic_closures WHERE closed_on = :closed_on LIMIT 1'
        );
        $closure->execute(['closed_on' => $localDate->format('Y-m-d')]);

        return $closure->fetchColumn() === false ? $hours : null;
    }

    /** @return list<array{date: string, reason: string|null}> */
    public function closures(): array
    {
        $statement = $this->pdo->query(
            'SELECT closed_on, reason FROM clinic_closures ORDER BY closed_on'
        );

        $closures = [];
        if ($statement === false) {
            return $closures;
        }

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date = $row['closed_on'] ?? '';
            if (!is_string($date)) {
                continue;
            }

            $reason = $row['reason'] ?? null;
            $closures[] = [
                'date' => $date,
                'reason' => is_string($reason) && $reason !== '' ? $reason : null,
            ];
        }

        return $closures;
    }

    /** @param array<int, OpeningHours> $week */
    public function saveWeek(array $week): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO clinic_hours (weekday, closed, opens, closes, updated_at)
             VALUES (:weekday, :closed, :opens, :closes, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                 closed = :closed_update,
                 opens = :opens_update,
                 closes = :closes_update,
                 updated_at = UTC_TIMESTAMP()'
        );

        $this->pdo->beginTransaction();

        try {
            foreach ($week as $hours) {
                $statement->execute([
                    'weekday' => $hours->weekday,
                    'closed' => $hours->closed ? 1 : 0,
                    'opens' => $hours->opens,
                    'closes' => $hours->closes,
                    'closed_update' => $hours->closed ? 1 : 0,
                    'opens_update' => $hours->opens,
                    'closes_update' => $hours->closes,
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function addClosure(string $date, ?string $reason): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO clinic_closures (closed_on, reason, created_at)
             VALUES (:closed_on, :reason, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE reason = :reason_update'
        );
        $statement->execute([
            'closed_on' => $date,
            'reason' => $reason,
            'reason_update' => $reason,
        ]);
    }

    public function removeClosure(string $date): void
    {
        $statement = $this->pdo->prepare('DELETE FROM clinic_closures WHERE closed_on = :closed_on');
        $statement->execute(['closed_on' => $date]);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): ?OpeningHours
    {
        $weekday = $row['weekday'] ?? null;
        $opens = OpeningHours::normalizeTime(self::text($row, 'opens'));
        $closes = OpeningHours::normalizeTime(self::text($row, 'closes'));

        if (!is_int($weekday) && !(is_string($weekday) && ctype_digit($weekday))) {
            return null;
        }

        if ($opens === null || $closes === null) {
            return null;
        }

        $closed = self::text($row, 'closed') === '1';

        return new OpeningHours((int) $weekday, $closed, $opens, $closes);
    }

    /** @param array<string, mixed> $row */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => '',
        };
    }
}
