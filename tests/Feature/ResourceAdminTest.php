<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Domain\Resource;
use App\Domain\ScheduleBoard;
use App\Domain\ValidationException;
use App\Repositories\PdoResourceAdminRepository;
use App\Repositories\PdoResourceBlockRepository;
use App\Repositories\PdoScheduleRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * "A new suite can be scheduled without SQL", asserted against MySQL.
 *
 * The unit tests prove the pieces; this proves the chain a receptionist actually
 * walks: add a room, give it a week, and find its slots on the board — with the
 * scheduling read and the admin write agreeing on the same rows.
 */
final class ResourceAdminTest extends TestCase
{
    private const DAY = '2031-03-04';

    private ?PDO $pdo = null;

    private ?PdoResourceAdminRepository $admin = null;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        Env::load($root . '/.env');
        $config = new Config($root);

        try {
            $this->pdo = Connection::create($config->database());
        } catch (PDOException | RuntimeException $exception) {
            self::markTestSkipped('MySQL is not reachable: ' . $exception->getMessage());
        }

        $this->admin = new PdoResourceAdminRepository($this->pdo);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->cleanUp();
        }

        $this->pdo = null;
        $this->admin = null;
    }

    public function testANewSuiteWithAWeekIsOfferedByTheBoard(): void
    {
        $admin = $this->admin();
        self::assertNotNull($admin);

        $room = $admin->create('tst-suite', 'Test Suite', 'suite', 'Ultrasound', true);
        self::assertTrue($room->isActive);

        // The board is asked before any hours exist: a room with no week is never
        // offered, which is the safe default rather than an all-day one.
        self::assertSame([], $this->board()->freeSlots($room->id, $this->day(), $this->zone(), 60, $this->now()));

        $admin->replaceDefaultWeek($room->id, $this->week(2, '08:00', '12:00'));

        $slots = $this->board()->freeSlots($room->id, $this->day(), $this->zone(), 60, $this->now());
        $labels = array_map(static fn (array $slot): string => $slot['label'], $slots);

        self::assertContains('08:00', $labels);
        self::assertContains('11:00', $labels);
        self::assertNotContains('12:00', $labels, 'A 60-minute slot may not start at closing time.');
    }

    public function testOnlyTheNamedDayOpens(): void
    {
        $admin = $this->admin();
        self::assertNotNull($admin);

        $room = $admin->create('tst-suite', 'Test Suite', 'suite', null, true);
        $admin->replaceDefaultWeek($room->id, $this->week(2, '08:00', '12:00'));

        $week = $admin->defaultWeek($room->id);

        self::assertSame(['opens' => '08:00:00', 'closes' => '12:00:00'], $week[2]);
        self::assertNull($week[1]);
        self::assertNull($week[7]);
    }

    public function testADatedOverrideSurvivesAWeekSave(): void
    {
        $admin = $this->admin();
        self::assertNotNull($admin);

        $room = $admin->create('tst-suite', 'Test Suite', 'suite', null, true);
        $admin->replaceDefaultWeek($room->id, $this->week(2, '08:00', '12:00'));

        $override = $this->pdo()->prepare(
            'INSERT INTO resource_availability (resource_id, weekday, opens_at, closes_at, valid_from, valid_to)
             VALUES (:id, 2, :opens, :closes, :from, :to)'
        );
        $override->execute([
            'id' => $room->id,
            'opens' => '13:00:00',
            'closes' => '17:00:00',
            'from' => '2031-03-01',
            'to' => '2031-03-31',
        ]);

        $admin->replaceDefaultWeek($room->id, $this->week(2, '09:00', '11:00'));

        $count = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM resource_availability
              WHERE resource_id = :id AND valid_from IS NOT NULL'
        );
        $count->execute(['id' => $room->id]);

        self::assertSame(1, (int) $count->fetchColumn(), 'A week save deleted a dated rule.');

        // And the override is what the schedule reads on that date.
        $windows = (new PdoScheduleRepository($this->pdo()))->windowsFor($room->id, 2, '2031-03-04');
        self::assertCount(2, $windows);
    }

    public function testDeactivatingARoomRemovesItFromTheBoardButKeepsItOnTheAdminList(): void
    {
        $admin = $this->admin();
        self::assertNotNull($admin);

        $room = $admin->create('tst-suite', 'Test Suite', 'suite', null, true);
        $admin->update($room->id, 'Test Suite', 'suite', null, false);

        $scheduled = array_map(
            static fn (Resource $resource): int => $resource->id,
            (new PdoScheduleRepository($this->pdo()))->resources(),
        );
        $listed = array_map(
            static fn (Resource $resource): int => $resource->id,
            $admin->all(),
        );

        self::assertNotContains($room->id, $scheduled, 'A deactivated room was still offered.');
        self::assertContains($room->id, $listed, 'A deactivated room vanished from administration.');
    }

    public function testADuplicateCodeIsRefusedWithAFieldError(): void
    {
        $admin = $this->admin();
        self::assertNotNull($admin);

        $admin->create('tst-suite', 'Test Suite', 'suite', null, true);

        $this->expectException(ValidationException::class);
        $admin->create('tst-suite', 'Another Suite', 'room', null, true);
    }

    public function testAWindowThatEndsBeforeItStartsIsRefused(): void
    {
        $admin = $this->admin();
        self::assertNotNull($admin);

        $room = $admin->create('tst-suite', 'Test Suite', 'suite', null, true);

        $this->expectException(ValidationException::class);
        $admin->replaceDefaultWeek($room->id, $this->week(2, '15:00', '09:00'));
    }

    /** @return array<int, array{opens: string, closes: string}|null> */
    private function week(int $weekday, string $opens, string $closes): array
    {
        $week = array_fill(1, 7, null);
        $week[$weekday] = ['opens' => $opens, 'closes' => $closes];

        return $week;
    }

    private function board(): ScheduleBoard
    {
        return new ScheduleBoard(
            new PdoScheduleRepository($this->pdo()),
            new PdoResourceBlockRepository($this->pdo()),
        );
    }

    private function day(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::DAY . ' 00:00:00', $this->zone());
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-10 08:00:00', new DateTimeZone('UTC'));
    }

    private function zone(): DateTimeZone
    {
        return new DateTimeZone('Africa/Addis_Ababa');
    }

    private function admin(): ?PdoResourceAdminRepository
    {
        return $this->admin;
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new RuntimeException('The database connection was not initialised.');
        }

        return $this->pdo;
    }

    private function cleanUp(): void
    {
        $pdo = $this->pdo;

        if ($pdo === null) {
            return;
        }

        $ids = $pdo->prepare('SELECT id FROM resources WHERE code LIKE :code');
        $ids->execute(['code' => 'tst-%']);
        $found = $ids->fetchAll(PDO::FETCH_COLUMN);

        foreach ($found as $id) {
            $pdo->prepare('DELETE FROM resource_availability WHERE resource_id = :id')->execute(['id' => $id]);
            $pdo->prepare('DELETE FROM resource_blocks WHERE resource_id = :id')->execute(['id' => $id]);
            $pdo->prepare('DELETE FROM resources WHERE id = :id')->execute(['id' => $id]);
        }
    }
}
