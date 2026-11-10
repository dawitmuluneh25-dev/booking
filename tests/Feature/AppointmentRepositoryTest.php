<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Database\PdoTransaction;
use App\Domain\Actor;
use App\Domain\Appointment;
use App\Domain\AppointmentStatus;
use App\Domain\ResourceNotFoundException;
use App\Domain\Role;
use App\Domain\SlotUnavailableException;
use App\Repositories\PdoAppointmentRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The occupancy guard against MySQL.
 *
 * The unit test proves the command asks the right questions; this proves the
 * database refuses the second answer even when the question was never asked. The
 * primary key on `resource_slots` is the mechanism, so it has to be exercised
 * against the real table.
 */
final class AppointmentRepositoryTest extends TestCase
{
    private const NOW = '2026-11-08 16:00:00';

    private ?PDO $pdo = null;

    private ?PdoAppointmentRepository $repository = null;

    private int $actorUserId = 0;

    private int $referralId = 0;

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

        $this->actorUserId = $this->insertUser();
        $this->referralId = $this->insertReferral();
        $this->repository = new PdoAppointmentRepository($this->pdo, new PdoTransaction($this->pdo));
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec(
                'DELETE FROM appointments WHERE referral_id = ' . $this->referralId
            );
            $this->pdo->exec('DELETE FROM referrals WHERE id = ' . $this->referralId);
            $this->pdo->exec('DELETE FROM users WHERE id = ' . $this->actorUserId);
        }

        $this->pdo = null;
        $this->repository = null;
    }

    public function testBookingWritesTheAppointmentItsResourceAndItsQuanta(): void
    {
        $appointment = $this->book('2026-11-21 10:00:00', 30);

        self::assertGreaterThan(0, $appointment->id);

        $slots = $this->pdo()->prepare(
            'SELECT slot_start FROM resource_slots WHERE appointment_id = :id ORDER BY slot_start'
        );
        $slots->execute(['id' => $appointment->id]);
        self::assertSame(
            ['2026-11-21 10:00:00', '2026-11-21 10:15:00'],
            array_map(
                static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                $slots->fetchAll(PDO::FETCH_COLUMN),
            ),
        );

        $link = $this->pdo()->prepare(
            'SELECT resource_id, role FROM appointment_resources WHERE appointment_id = :id'
        );
        $link->execute(['id' => $appointment->id]);
        $row = $link->fetch();
        self::assertIsArray($row);
        self::assertSame('primary', is_scalar($row['role'] ?? null) ? (string) $row['role'] : '');
    }

    public function testTheSecondBookingOfAQuantumIsRefusedByTheKeyAndWritesNothing(): void
    {
        $this->book('2026-11-21 11:00:00', 30);

        $second = $this->attempt('2026-11-21 11:15:00', 30);

        try {
            $this->repository()->book(
                $second,
                $this->resourceId(),
                $this->slots('2026-11-21 11:15:00', 30),
                $this->actor(),
            );
            self::fail('The same quantum was booked twice.');
        } catch (SlotUnavailableException $exception) {
            self::assertSame('2026-11-21 11:15:00', $exception->slotStartUtc);
        }

        // The failed booking left no appointment behind: the whole unit of work went.
        $orphans = $this->pdo()->prepare('SELECT COUNT(*) FROM appointments WHERE reference = :reference');
        $orphans->execute(['reference' => $second->reference]);
        self::assertSame(0, (int) $orphans->fetchColumn());
    }

    public function testAnUnknownResourceIsRefusedBeforeAnythingIsWritten(): void
    {
        $appointment = $this->attempt('2026-11-21 12:00:00', 15);

        $this->expectException(ResourceNotFoundException::class);
        $this->repository()->book($appointment, 999999, $this->slots('2026-11-21 12:00:00', 15), $this->actor());
    }

    public function testAResourceKindThatIsNotBookableIsRefused(): void
    {
        // A clinician is a resource but not a room; booking one would take a
        // person out of the pool rather than a place to work in.
        $code = 'clinician-probe-' . bin2hex(random_bytes(4));
        $insert = $this->pdo()->prepare(
            "INSERT INTO resources (code, name, kind, created_at, updated_at)
             VALUES (:code, 'Probe clinician', 'clinician', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $insert->execute(['code' => $code]);
        $clinician = (int) $this->pdo()->lastInsertId();

        $appointment = $this->attempt('2026-11-21 13:00:00', 15);

        try {
            $this->expectException(ResourceNotFoundException::class);
            $this->repository()->book(
                $appointment,
                $clinician,
                $this->slots('2026-11-21 13:00:00', 15),
                $this->actor(),
            );
        } finally {
            $this->pdo()->exec('DELETE FROM resources WHERE id = ' . $clinician);
        }
    }

    private function book(string $startsAt, int $minutes): Appointment
    {
        return $this->repository()->book(
            $this->attempt($startsAt, $minutes),
            $this->resourceId(),
            $this->slots($startsAt, $minutes),
            $this->actor(),
        );
    }

    private function attempt(string $startsAt, int $minutes): Appointment
    {
        return new Appointment(
            id: 0,
            reference: 'BK-TEST-' . bin2hex(random_bytes(4)),
            referralId: $this->referralId,
            status: AppointmentStatus::Scheduled,
            version: 1,
            startsAtUtc: $startsAt,
            endsAtUtc: (new \DateTimeImmutable($startsAt, new \DateTimeZone('UTC')))
                ->modify('+' . $minutes . ' minutes')
                ->format('Y-m-d H:i:s'),
            durationMinutes: $minutes,
            patientName: 'Selamawit Girma',
            patientPhone: '+251911000333',
            createdByUserId: $this->actorUserId,
            createdAtUtc: self::NOW,
        );
    }

    /** @return list<string> */
    private function slots(string $startsAt, int $minutes): array
    {
        $slots = [];
        $start = new \DateTimeImmutable($startsAt, new \DateTimeZone('UTC'));

        for ($offset = 0; $offset < $minutes; $offset += 15) {
            $slot = $start->modify('+' . $offset . ' minutes');
            self::assertNotFalse($slot);
            $slots[] = $slot->format('Y-m-d H:i:s');
        }

        return $slots;
    }

    private function resourceId(): int
    {
        $query = $this->pdo()->query("SELECT id FROM resources WHERE code = 'suite-1' LIMIT 1");
        $id = $query === false ? false : $query->fetchColumn();

        if ($id === false) {
            self::markTestSkipped('Resource suite-1 is not seeded.');
        }

        return (int) $id;
    }

    private function repository(): PdoAppointmentRepository
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

    private function actor(): Actor
    {
        return new Actor($this->actorUserId, Role::Staff);
    }

    private function insertUser(): int
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO users (email, name, role, password_hash, created_at, updated_at)
             VALUES (:email, :name, :role, :hash, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([
            'email' => 'booking-probe-' . bin2hex(random_bytes(6)) . '@example.test',
            'name' => 'Booking Probe',
            'role' => 'staff',
            'hash' => str_repeat('0', 60),
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function insertReferral(): int
    {
        $statement = $this->pdo()->prepare(
            "INSERT INTO referrals
                (reference, status, patient_name, patient_phone, procedure_code,
                 submitted_by_user_id, submitted_on_behalf_of, created_at, updated_at)
             VALUES (:reference, 'converted_to_appointment', 'Selamawit Girma', '+251911000333',
                 'image_guided_biopsy', :user_id, 'staff', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $statement->execute([
            'reference' => 'RF-PROBE-' . bin2hex(random_bytes(4)),
            'user_id' => $this->actorUserId,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }
}
