<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Application;
use App\Database\Connection;
use App\Database\DemoSeedGuard;
use App\Database\DemoSeedRefused;
use App\Database\DemoSeeder;
use App\Database\Migrator;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves the claim the backlog makes for this command: an empty database becomes a
 * demonstration in one call.
 *
 * It runs against a schema built from nothing, because that is the only arrangement
 * in which the claim means anything. A seed tested on a populated database would
 * pass while the guard that stops it from writing beside real records was broken —
 * the guard is exactly what a convenient fixture database would hide.
 *
 * The connection is pointed at the scratch schema through a throwaway root holding a
 * copy of `.env` with one line changed. Copying rather than mutating the real file
 * keeps the developer's environment untouched, and booting from that root means the
 * seeder sees the database it is actually writing to.
 */
final class DemoSeedTest extends TestCase
{
    /** Provisioned with full privileges by `.tools/bootstrap.sql`. */
    private const SCRATCH_DATABASE = 'booking_app_migration_test';

    private const PASSWORD = 'demo-password-123';

    private static ?PDO $pdo = null;

    private static string $root = '';

    /** A root with no `.env` of its own and the real `routes/` linked in. */
    private static string $scratchRoot = '';

    private static string $scratchEnv = '';

    private static ?Application $application = null;

    private static bool $available = false;

    private static string $unavailableReason = '';

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);

        try {
            self::$pdo = self::openScratchSchema();
        } catch (PDOException | RuntimeException $exception) {
            self::$unavailableReason = $exception->getMessage();

            return;
        }

        // The schema is built once for the class. Migrating per test would rebuild
        // twenty-four migrations eight times to prove something migrations are not
        // the subject of.
        $pdo = self::pdo();
        self::dropAllTables($pdo);
        (new Migrator($pdo, self::$root . '/database/migrations'))->migrate();

        self::$scratchRoot = self::prepareScratchRoot();
        self::$scratchEnv = self::$scratchRoot . DIRECTORY_SEPARATOR . 'scratch.env';

        // Loaded before boot, and boot's own load is a no-op because the scratch root
        // deliberately has no `.env`. Booting first would open the connection while
        // registering routes — against the developer's own database.
        \App\Bootstrap\Env::load(self::$scratchEnv);

        self::$application = Application::boot(self::$scratchRoot);
        self::$available = true;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pdo !== null) {
            self::dropAllTables(self::$pdo);
        }

        if (self::$scratchEnv !== '' && is_file(self::$scratchEnv)) {
            unlink(self::$scratchEnv);
        }

        if (self::$scratchRoot !== '') {
            // A junction is removed with rmdir, which deletes the link and not the
            // real routes directory behind it.
            exec('cmd /c rmdir "' . self::$scratchRoot . '\routes" 2>&1');
            @rmdir(self::$scratchRoot);
        }

        // The static environment cache now holds the scratch database; put the
        // developer's own settings back so later test classes are unaffected.
        \App\Bootstrap\Env::load(self::$root . '/.env');

        self::$application = null;
        self::$pdo = null;
        self::$available = false;
        self::$scratchEnv = '';
        self::$scratchRoot = '';
    }

    protected function setUp(): void
    {
        if (!self::$available) {
            self::markTestSkipped('MySQL is not reachable for the demo seed test: ' . self::$unavailableReason);
        }

        self::emptyAllTables(self::pdo());
    }

    /**
     * Empty every table, which is the state the seed insists on. Faster than
     * rebuilding the schema and indistinguishable to the code under test.
     */
    private static function emptyAllTables(PDO $pdo): void
    {
        $statement = $pdo->query(
            "SELECT TABLE_NAME AS name FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        );

        if ($statement === false) {
            return;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || !isset($row['name']) || !is_string($row['name'])) {
                continue;
            }

            // `procedures` is reference data the migrations create, not records the
            // demonstration produces. Emptying it would test a state no freshly
            // migrated database is ever in.
            if (in_array($row['name'], ['migrations', 'procedures'], true)) {
                continue;
            }

            $pdo->exec('TRUNCATE TABLE `' . str_replace('`', '``', $row['name']) . '`');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testOneCommandTurnsAnEmptyDatabaseIntoADemonstration(): void
    {
        $report = self::seeder('local')->seed(self::PASSWORD);

        self::assertTrue($report->seeded());
        self::assertSame(5, $report->users, 'One account per role a demonstration needs.');
        self::assertSame(2, $report->resources);
        self::assertSame(10, $report->referrals);
        self::assertSame(3, $report->appointments);
        self::assertSame(1, $report->urgentCases);
    }

    /**
     * Every screen the demonstration is meant to show has something in it. A seed
     * that produced ten referrals all in one state would satisfy the counts and
     * demonstrate nothing.
     */
    public function testEveryQueueTheDemonstrationShowsIsOccupied(): void
    {
        self::seeder('local')->seed(self::PASSWORD);

        $statement = self::pdo()->query('SELECT status, COUNT(*) AS total FROM referrals GROUP BY status');
        self::assertNotFalse($statement);
        $statuses = $statement->fetchAll(PDO::FETCH_ASSOC);

        $counts = [];

        foreach ($statuses as $row) {
            if (is_array($row) && isset($row['status'], $row['total']) && is_string($row['status'])) {
                $counts[$row['status']] = (int) $row['total'];
            }
        }

        foreach (
            [
                'submitted',
                'administrative_review',
                'clinical_review',
                'approved_for_scheduling',
                'converted_to_appointment',
                'deferred',
                'awaiting_information',
                'clinically_approved',
            ] as $status
        ) {
            self::assertArrayHasKey($status, $counts, 'No referral is waiting in ' . $status . '.');
        }
    }

    public function testTheBookedReferralsHaveAppointmentsOnADayTheRoomsAreOpen(): void
    {
        self::seeder('local')->seed(self::PASSWORD);

        // Appointments are stored in UTC, so the weekday has to be taken in clinic
        // time before it can be compared with a weekly rule — the same conversion the
        // product performs, and the one this test would otherwise get wrong by
        // reading the stored date directly.
        $zone = new \DateTimeZone(self::app()->config()->timezone());

        $open = [];

        $availability = self::pdo()->query(
            'SELECT resource_id, weekday FROM resource_availability
              WHERE valid_from IS NULL AND valid_to IS NULL'
        );

        self::assertNotFalse($availability);

        foreach ($availability->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row) && isset($row['resource_id'], $row['weekday'])) {
                $open[(int) $row['resource_id']][(int) $row['weekday']] = true;
            }
        }

        $booked = self::pdo()->query(
            'SELECT a.starts_at, ar.resource_id
               FROM appointments AS a
               JOIN appointment_resources AS ar ON ar.appointment_id = a.id'
        );

        self::assertNotFalse($booked);

        $appointments = $booked->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(3, $appointments, 'The demonstration expects three booked appointments.');

        foreach ($appointments as $appointment) {
            self::assertIsArray($appointment);

            $startsAt = is_string($appointment['starts_at'] ?? null) ? $appointment['starts_at'] : '';
            $resourceId = is_numeric($appointment['resource_id'] ?? null)
                ? (int) $appointment['resource_id']
                : 0;

            $local = (new \DateTimeImmutable($startsAt, new \DateTimeZone('UTC')))->setTimezone($zone);
            $weekday = (int) $local->format('N');

            self::assertArrayHasKey(
                $weekday,
                $open[$resourceId] ?? [],
                sprintf(
                    'The appointment at %s is on a %s, when its room has no opening hours.',
                    $local->format('Y-m-d H:i'),
                    $local->format('l'),
                ),
            );
        }
    }

    /** The demo has to be safe to run twice, or nobody will run it at all. */
    public function testRunningTheSeedTwiceChangesNothing(): void
    {
        self::seeder('local')->seed(self::PASSWORD);

        $before = self::counts();

        $second = self::seeder('local')->seed(self::PASSWORD);

        self::assertFalse($second->seeded(), 'A second run must report that it did nothing.');
        self::assertSame($before, self::counts(), 'A second run must not add records.');
    }

    /**
     * The guard that matters most in practice: a database with somebody's records in
     * it is refused, and the refusal says what it found.
     */
    public function testADatabaseWithRecordsIsRefused(): void
    {
        self::pdo()->exec(
            "INSERT INTO users (email, name, role, password_hash, is_active, created_at, updated_at)
             VALUES ('real@hospital.test', 'Real Person', 'staff', 'x', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );

        try {
            self::seeder('local')->seed(self::PASSWORD);
            self::fail('The seed wrote to a database that already held records.');
        } catch (DemoSeedRefused $refusal) {
            self::assertStringContainsString('already contains', $refusal->getMessage());
        }

        self::assertSame(0, self::counts()['referrals'], 'A refused seed must leave nothing behind.');
    }

    /** Even with records present, production is refused for its own reason first. */
    public function testProductionIsRefusedBeforeTheDatabaseIsRead(): void
    {
        $seeder = self::seeder('production');

        try {
            $seeder->requirePermittedEnvironment();
            $seeder->requireConfirmation(true);
            $seeder->seed(self::PASSWORD);
            self::fail('The seed ran against production.');
        } catch (DemoSeedRefused $refusal) {
            self::assertStringContainsString('APP_ENV', $refusal->getMessage());
        }

        self::assertSame(0, self::counts()['users']);
    }

    /**
     * The dataset's contact details cannot reach a human: mail goes to a reserved
     * domain and the subscriber prefix is unassigned. This is the property that
     * keeps "no real patient data" true even if somebody hands the demo to a real
     * referrer by mistake.
     */
    public function testNoContactDetailInTheDatasetCanReachAPerson(): void
    {
        self::seeder('local')->seed(self::PASSWORD);

        $userStatement = self::pdo()->query('SELECT email FROM users');
        self::assertNotFalse($userStatement);
        $emails = $userStatement->fetchAll(PDO::FETCH_COLUMN);

        self::assertNotSame([], $emails);

        foreach ($emails as $email) {
            self::assertIsString($email);
            self::assertStringEndsWith('@' . DemoSeeder::EMAIL_DOMAIN, $email);
        }

        $phoneStatement = self::pdo()->query('SELECT patient_phone FROM referrals');
        self::assertNotFalse($phoneStatement);
        $phones = $phoneStatement->fetchAll(PDO::FETCH_COLUMN);

        self::assertNotSame([], $phones);

        foreach ($phones as $phone) {
            self::assertIsString($phone);
            self::assertStringStartsWith('+251 000 000 ', $phone, 'A demo number uses a reachable prefix.');
        }
    }

    /**
     * A seed that half-succeeds is worse than one that fails: it leaves fictional
     * users with no demonstration behind them, and the next run refuses because the
     * database is no longer empty.
     *
     * The collision is planted on a resource code, because that is checked after the
     * users are written and before any referral is — so the failure lands in the
     * middle of the work rather than at its start.
     */
    public function testAFailurePartwayLeavesNothingBehind(): void
    {
        self::pdo()->exec(
            "INSERT INTO resources (code, name, kind, is_active, created_at, updated_at)
             VALUES ('demo-room-1', 'Room belonging to somebody else', 'room', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );

        try {
            self::seeder('local')->seed(self::PASSWORD);
            self::fail('The seed reported success despite a collision.');
        } catch (\Throwable $failure) {
            self::assertStringNotContainsString(
                'DemoSeedRefused',
                $failure::class,
                'A collision is a failure, not a polite refusal.',
            );
        }

        $counts = self::counts();

        self::assertSame(0, $counts['users'], 'The accounts created before the failure were not rolled back.');
        self::assertSame(0, $counts['referrals'], 'Referrals survived a failed seed.');
        self::assertSame(0, $counts['appointments']);
    }

    private static function seeder(string $environment): DemoSeeder
    {
        return new DemoSeeder(self::app(), new DemoSeedGuard($environment));
    }

    private static function app(): Application
    {
        if (self::$application === null) {
            throw new RuntimeException('The scratch application was not built.');
        }

        return self::$application;
    }

    /** @return array{referrals: int, users: int, appointments: int} */
    private static function counts(): array
    {
        $count = static function (string $table): int {
            $statement = self::pdo()->query('SELECT COUNT(*) FROM ' . $table);
            $value = $statement === false ? null : $statement->fetchColumn();

            return is_numeric($value) ? (int) $value : 0;
        };

        return [
            'referrals' => $count('referrals'),
            'users' => $count('users'),
            'appointments' => $count('appointments'),
        ];
    }

    private static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new RuntimeException('The scratch schema is not open.');
        }

        return self::$pdo;
    }

    /**
     * A throwaway application root.
     *
     * It holds a rewritten copy of `.env` and a junction to the real `routes/`
     * directory, and deliberately no `.env`, so `Application::boot()` finds no
     * environment file to load and keeps the scratch one already in memory.
     */
    private static function prepareScratchRoot(): string
    {
        $source = self::$root . '/.env';

        if (!is_file($source)) {
            throw new RuntimeException('There is no .env to copy for the demo seed test.');
        }

        $contents = file_get_contents($source);

        if ($contents === false) {
            throw new RuntimeException('The .env file could not be read.');
        }

        $replaced = preg_replace('/^DB_NAME=.*$/m', 'DB_NAME=' . self::SCRATCH_DATABASE, $contents);

        if ($replaced === null) {
            throw new RuntimeException('The database name could not be rewritten for the demo seed test.');
        }

        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'booking-demo-' . bin2hex(random_bytes(6));

        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            throw new RuntimeException('The scratch application root could not be created.');
        }

        file_put_contents($root . DIRECTORY_SEPARATOR . 'scratch.env', $replaced);

        exec('cmd /c mklink /J "' . $root . '\routes" "' . self::$root . '\routes" 2>&1', $output, $status);

        if ($status !== 0) {
            throw new RuntimeException('The routes directory could not be linked into the scratch root.');
        }

        return $root;
    }

    private static function openScratchSchema(): PDO
    {
        $root = dirname(__DIR__, 2);
        \App\Bootstrap\Env::load($root . '/.env');

        $settings = (new \App\Bootstrap\Config($root))->database();

        try {
            return Connection::create(['database' => self::SCRATCH_DATABASE] + $settings, true);
        } catch (PDOException | RuntimeException $exception) {
            throw new RuntimeException(
                'The scratch schema ' . self::SCRATCH_DATABASE . ' is not reachable: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }
    }

    private static function dropAllTables(PDO $pdo): void
    {
        $statement = $pdo->query(
            "SELECT TABLE_NAME AS name FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        );

        if ($statement === false) {
            return;
        }

        $tables = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row) && isset($row['name']) && is_string($row['name'])) {
                $tables[] = $row['name'];
            }
        }

        if ($tables === []) {
            return;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
