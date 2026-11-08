<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Diagnostics;
use PHPUnit\Framework\TestCase;

final class DiagnosticsTest extends TestCase
{
    public function testDeadOutboxRowsFailTheCheck(): void
    {
        self::assertSame('fail', Diagnostics::outboxState(0, 1, null));
    }

    public function testStalePendingRowsWarn(): void
    {
        self::assertSame('warn', Diagnostics::outboxState(3, 0, 60));
    }

    public function testFreshPendingRowsAreFine(): void
    {
        self::assertSame('ok', Diagnostics::outboxState(3, 0, 2));
        self::assertSame('ok', Diagnostics::outboxState(0, 0, null));
    }

    public function testMissingBackupFails(): void
    {
        self::assertSame('fail', Diagnostics::backupState(null));
    }

    public function testOldBackupFailsAndAgeingBackupWarns(): void
    {
        self::assertSame('fail', Diagnostics::backupState(8 * 86400));
        self::assertSame('warn', Diagnostics::backupState(3 * 86400));
        self::assertSame('ok', Diagnostics::backupState(3600));
    }

    public function testDiskThresholds(): void
    {
        self::assertSame('fail', Diagnostics::diskState(10 * 1024 * 1024));
        self::assertSame('warn', Diagnostics::diskState(100 * 1024 * 1024));
        self::assertSame('ok', Diagnostics::diskState(1024 * 1024 * 1024));
    }

    public function testLogStateWarnsOnAnyError(): void
    {
        self::assertSame('ok', Diagnostics::logState(0));
        self::assertSame('warn', Diagnostics::logState(1));
    }

    public function testHumanBytesScales(): void
    {
        self::assertSame('512 B', Diagnostics::humanBytes(512));
        self::assertSame('1 KiB', Diagnostics::humanBytes(1024));
        self::assertSame('1 MiB', Diagnostics::humanBytes(1024 * 1024));
        self::assertSame('1.5 MiB', Diagnostics::humanBytes((int) (1.5 * 1024 * 1024)));
    }
}
