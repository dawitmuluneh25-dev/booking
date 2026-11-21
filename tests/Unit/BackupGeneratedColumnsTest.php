<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\DatabaseBackup;
use PHPUnit\Framework\TestCase;

/**
 * A dump names columns in its INSERT statements, so it has to name only the ones a
 * server will accept.
 *
 * This exists because a dump that "looked complete" was unrestorable: `SELECT *`
 * expands to generated columns, the INSERT then named `published_content.live_key`,
 * and MariaDB refused the whole statement with 1906 — "The value specified for
 * generated column 'live_key' in table 'published_content' has been ignored". The
 * restore drill failed on the host, having restored 29 of 51 tables, and the backup
 * taken the hour before was equally unrestorable. Nothing in the suite noticed,
 * because nothing tested the dump.
 *
 * The subtle half is `DEFAULT_GENERATED`: MySQL reports it for an ordinary column
 * with an expression default. Excluding it would drop real data silently, which is
 * a worse failure than the one being fixed — so the filter matches the two generated
 * forms exactly.
 */
final class BackupGeneratedColumnsTest extends TestCase
{
    public function testStoredAndVirtualGeneratedColumnsAreExcluded(): void
    {
        $columns = DatabaseBackup::writableColumns([
            ['Field' => 'id', 'Extra' => 'auto_increment'],
            ['Field' => 'content_key', 'Extra' => ''],
            ['Field' => 'status', 'Extra' => ''],
            ['Field' => 'live_key', 'Extra' => 'STORED GENERATED'],
            ['Field' => 'search_blob', 'Extra' => 'VIRTUAL GENERATED'],
            ['Field' => 'created_at', 'Extra' => ''],
        ]);

        self::assertSame(
            ['id', 'content_key', 'status', 'created_at'],
            $columns,
            'A dump must not name a generated column in an INSERT.',
        );
    }

    public function testAColumnWithAnExpressionDefaultIsKept(): void
    {
        $columns = DatabaseBackup::writableColumns([
            ['Field' => 'id', 'Extra' => 'auto_increment'],
            ['Field' => 'updated_at', 'Extra' => 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP'],
            ['Field' => 'label', 'Extra' => 'DEFAULT_GENERATED'],
        ]);

        self::assertSame(
            ['id', 'updated_at', 'label'],
            $columns,
            'DEFAULT_GENERATED marks a real column; dropping it would lose its values.',
        );
    }

    public function testATableOfOnlyGeneratedColumnsYieldsNoColumns(): void
    {
        self::assertSame(
            [],
            DatabaseBackup::writableColumns([
                ['Field' => 'computed_a', 'Extra' => 'STORED GENERATED'],
                ['Field' => 'computed_b', 'Extra' => 'VIRTUAL GENERATED'],
            ]),
        );
    }

    public function testRowsWithoutAFieldNameAreIgnoredRatherThanGuessed(): void
    {
        $columns = DatabaseBackup::writableColumns([
            ['Field' => 'id', 'Extra' => ''],
            ['Extra' => ''],
            ['Field' => '', 'Extra' => ''],
            ['Field' => 'name', 'Extra' => ''],
        ]);

        self::assertSame(['id', 'name'], $columns);
    }
}
