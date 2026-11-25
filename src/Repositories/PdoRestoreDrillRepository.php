<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\RestoreDrillRepository;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Drill evidence, appended only.
 *
 * There is no `UPDATE` and no `DELETE` statement in this class, and that is deliberate rather than
 * incidental: the table exists so a reader can trust that a restore was tested. A repository that could
 * rewrite history would be worth nothing precisely when it mattered.
 */
final class PdoRestoreDrillRepository implements RestoreDrillRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(array $drill, string $verifiedAtUtc): void
    {
        $statement = $this->prepare(
            'INSERT INTO restore_drills
                (archive_name, archive_encrypted, target_database, tables_checked, rows_checked,
                 verdict, actor, verified_at)
             VALUES
                (:archive, :encrypted, :target, :tables, :rows, :verdict, :actor, :verified_at)'
        );

        $statement->execute([
            'archive' => $drill['archive'],
            'encrypted' => $drill['encrypted'] ? 1 : 0,
            'target' => $drill['target'],
            'tables' => $drill['tables'],
            'rows' => $drill['rows'],
            'verdict' => $drill['verdict'],
            'actor' => $drill['actor'],
            'verified_at' => $verifiedAtUtc,
        ]);
    }

    public function latestSuccessful(?string $archive = null): ?array
    {
        $sql = "SELECT archive_name, archive_encrypted, target_database, tables_checked, rows_checked, verified_at
                  FROM restore_drills
                 WHERE verdict = 'ok'";

        $parameters = [];

        if ($archive !== null) {
            $sql .= ' AND archive_name = :archive';
            $parameters['archive'] = $archive;
        }

        $sql .= ' ORDER BY verified_at DESC, id DESC LIMIT 1';

        $statement = $this->prepare($sql);
        $statement->execute($parameters);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        $tables = $row['tables_checked'] ?? 0;
        $rows = $row['rows_checked'] ?? 0;
        $encrypted = $row['archive_encrypted'] ?? 0;

        return [
            'archive' => $this->text($row['archive_name'] ?? null),
            'encrypted' => is_numeric($encrypted) && (int) $encrypted === 1,
            'target' => $this->text($row['target_database'] ?? null),
            'tables' => is_numeric($tables) ? (int) $tables : 0,
            'rows' => is_numeric($rows) ? (int) $rows : 0,
            'verifiedAt' => $this->text($row['verified_at'] ?? null),
        ];
    }

    public function count(): int
    {
        $statement = $this->prepare('SELECT COUNT(*) FROM restore_drills');
        $statement->execute();

        $count = $statement->fetchColumn();

        return is_numeric($count) ? (int) $count : 0;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);

        if ($statement === false) {
            throw new RuntimeException('The driver refused to prepare a restore-drill statement.');
        }

        return $statement;
    }
}
