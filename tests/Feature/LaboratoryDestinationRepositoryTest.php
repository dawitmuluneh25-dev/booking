<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Repositories\PdoLaboratoryDestinationRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The laboratory catalogue against MySQL.
 *
 * What the database has to hold is the shape the specimen slice will rely on: one
 * serial per laboratory, a serial that cannot be reissued, and a closed laboratory
 * that stays readable. The code and label are derived in PHP, so the interesting
 * assertions are about the serial.
 */
final class LaboratoryDestinationRepositoryTest extends TestCase
{
    private const NOW = '2026-11-13 10:00:00';

    private ?PDO $pdo = null;

    private ?PdoLaboratoryDestinationRepository $repository = null;

    /** @var list<int> */
    private array $createdIds = [];

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

        $this->repository = new PdoLaboratoryDestinationRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            foreach ($this->createdIds as $id) {
                $this->pdo->exec('DELETE FROM laboratory_destinations WHERE id = ' . $id);
            }
        }

        $this->createdIds = [];
        $this->pdo = null;
        $this->repository = null;
    }

    public function testALaboratoryRoundTripsAndItsCodeComesFromItsSerial(): void
    {
        $serial = $this->repository()->nextSerial();
        $stored = $this->repository()->create($serial, self::NOW);
        $this->createdIds[] = $stored->id;

        $reloaded = $this->repository()->findById($stored->id);

        self::assertNotNull($reloaded);
        self::assertSame($serial, $reloaded->serialNo);
        self::assertSame('PATHOLOGY-LAB-' . $serial, $reloaded->code());
        self::assertSame('Pathology Lab ' . $serial, $reloaded->label());
        self::assertTrue($reloaded->isActive);
    }

    public function testTheNextSerialIsOnePastTheHighestAndNotAReusedNumber(): void
    {
        $first = $this->repository()->create($this->repository()->nextSerial(), self::NOW);
        $this->createdIds[] = $first->id;

        $second = $this->repository()->create($this->repository()->nextSerial(), self::NOW);
        $this->createdIds[] = $second->id;

        self::assertSame($first->serialNo + 1, $second->serialNo);

        // Closing one must not hand its number to the next laboratory.
        $this->repository()->setActive($first->id, false, self::NOW);
        $third = $this->repository()->create($this->repository()->nextSerial(), self::NOW);
        $this->createdIds[] = $third->id;

        self::assertSame($second->serialNo + 1, $third->serialNo);
    }

    public function testTwoLaboratoriesCannotShareASerial(): void
    {
        $serial = $this->repository()->nextSerial();
        $stored = $this->repository()->create($serial, self::NOW);
        $this->createdIds[] = $stored->id;

        $this->expectException(PDOException::class);

        $this->repository()->create($serial, self::NOW);
    }

    public function testTheColumnRefusesASerialOutsideTheApprovedRange(): void
    {
        $refused = 0;

        foreach ([0, 1000] as $serial) {
            try {
                $this->pdo()->exec(
                    "INSERT INTO laboratory_destinations (serial_no, is_active, created_at, updated_at)
                     VALUES (" . $serial . ", 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
                );
            } catch (PDOException) {
                $refused++;
            }
        }

        self::assertSame(2, $refused, 'The serial range is not enforced by the column.');
    }

    public function testClosingALaboratoryRemovesItFromTheChoicesButNotFromTheRecord(): void
    {
        $stored = $this->repository()->create($this->repository()->nextSerial(), self::NOW);
        $this->createdIds[] = $stored->id;

        $this->repository()->setActive($stored->id, false, self::NOW);

        $active = array_filter(
            $this->repository()->active(),
            static fn ($destination): bool => $destination->id === $stored->id,
        );

        self::assertCount(0, $active);

        $reloaded = $this->repository()->findById($stored->id);

        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isActive);
    }

    private function repository(): PdoLaboratoryDestinationRepository
    {
        if ($this->repository === null) {
            throw new RuntimeException('The repository was not initialised.');
        }

        return $this->repository;
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new RuntimeException('The connection was not initialised.');
        }

        return $this->pdo;
    }
}
