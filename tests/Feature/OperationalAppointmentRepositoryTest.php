<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Domain\Actor;
use App\Domain\OperationalAppointment;
use App\Domain\Role;
use App\Repositories\PdoOperationalAppointmentRepository;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The appointment table against MySQL.
 *
 * The unit tests prove the rules; this proves the storage, and the storage has two obligations the
 * fakes cannot check. First, the migration's five rows exist before anybody fills them in — a screen
 * that lists nothing because the seeder did not run is a screen that silently omits appointments.
 * Second, a save is an upsert: a database restored from before migration 0048, or one where a row was
 * removed by hand, must still be fillable rather than throwing.
 *
 * The test leaves the table as it found it: every holder and deputy back to NULL. It is running against
 * a real schema that a person may be using.
 */
final class OperationalAppointmentRepositoryTest extends TestCase
{
    private ?PDO $pdo = null;

    private ?PdoOperationalAppointmentRepository $repository = null;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        Env::load($root . '/.env');
        $config = new Config($root);

        try {
            $this->pdo = Connection::create($config->database());
        } catch (PDOException | RuntimeException $exception) {
            self::markTestSkipped('No database available: ' . $exception->getMessage());
        }

        $this->repository = new PdoOperationalAppointmentRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->reset();
    }

    public function testTheMigrationSeededEveryAppointmentAsVacant(): void
    {
        $rows = $this->repository()->all();

        foreach (OperationalAppointment::cases() as $appointment) {
            self::assertArrayHasKey($appointment->value, $rows, 'The seeded row is missing for ' . $appointment->value);
        }

        self::assertCount(count(OperationalAppointment::cases()), $rows);
    }

    public function testSavingRecordsBothNamesAndTheAccountThatDidIt(): void
    {
        $this->repository()->save(
            OperationalAppointment::OperationsLead->value,
            'Trede Agua',
            'Dawit Muluneh',
            new Actor(42, Role::Admin),
            '2026-11-25 21:30:00',
        );

        $row = $this->repository()->all()[OperationalAppointment::OperationsLead->value];

        self::assertSame('Trede Agua', $row['holder']);
        self::assertSame('Dawit Muluneh', $row['deputy']);
        self::assertSame('user:42:admin', $row['updatedBy']);
        self::assertStringStartsWith('2026-11-25 21:30', (string) $row['updatedAt']);
    }

    public function testSavingNullClearsAnAppointment(): void
    {
        $this->repository()->save(
            OperationalAppointment::ReferralIntakeCoordinator->value,
            'Trede Agua',
            'Dawit Muluneh',
            new Actor(1, Role::Admin),
            '2026-11-25 21:30:00',
        );

        $this->repository()->save(
            OperationalAppointment::ReferralIntakeCoordinator->value,
            null,
            null,
            new Actor(1, Role::Admin),
            '2026-11-25 21:31:00',
        );

        $row = $this->repository()->all()[OperationalAppointment::ReferralIntakeCoordinator->value];

        self::assertNull($row['holder']);
        self::assertNull($row['deputy']);
    }

    public function testSavingWorksEvenWhenTheSeededRowIsMissing(): void
    {
        $key = OperationalAppointment::BackupEscalationDutyCoordinator->value;

        $this->query('DELETE FROM operational_appointments WHERE role_key = :key', ['key' => $key]);
        self::assertArrayNotHasKey($key, $this->repository()->all());

        $this->repository()->save(
            $key,
            'Trede Agua',
            'Dawit Muluneh',
            new Actor(1, Role::Admin),
            '2026-11-25 21:32:00',
        );

        $row = $this->repository()->all()[$key];

        self::assertSame('Trede Agua', $row['holder']);
    }

    private function reset(): void
    {
        $this->query('UPDATE operational_appointments SET holder_name = NULL, deputy_name = NULL');
    }

    /** @param array<string, mixed> $parameters */
    private function query(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);

        if ($statement === false) {
            self::fail('The statement could not be prepared.');
        }

        $statement->execute($parameters);

        return $statement;
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('No database connection: this test skipped itself in setUp().');
        }

        return $this->pdo;
    }

    private function repository(): PdoOperationalAppointmentRepository
    {
        if ($this->repository === null) {
            self::fail('No repository: this test skipped itself in setUp().');
        }

        return $this->repository;
    }
}
