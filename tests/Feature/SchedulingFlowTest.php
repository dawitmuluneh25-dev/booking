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
use App\Domain\AppointmentTransitionException;
use App\Domain\RescheduleTarget;
use App\Domain\Role;
use App\Domain\SlotUnavailableException;
use App\Repositories\PdoAppointmentRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The scheduling transaction against MySQL, where the ordering actually bites.
 *
 * The in-memory double reproduces the acquire-before-release rule; only the real
 * adapter can prove that the `DELETE ... NOT IN (...)` release, the primary-key
 * conflict and the rollback compose into the behaviour the specification asks
 * for. That combination is what leaves a patient booked or unbooked, so it is
 * worth a database test of its own.
 */
final class SchedulingFlowTest extends TestCase
{
    private const DAY = '2033-02-08';

    private ?PDO $pdo = null;

    private int $actorUserId = 0;

    private int $referralId = 0;

    private int $resourceId = 0;

    private int $otherResourceId = 0;

    private PdoAppointmentRepository $repository;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        Env::load($root . '/.env');

        try {
            $this->pdo = Connection::create((new Config($root))->database());
        } catch (PDOException | RuntimeException $exception) {
            self::markTestSkipped('MySQL is not reachable: ' . $exception->getMessage());
        }

        $this->actorUserId = $this->insertUser();
        $this->referralId = $this->insertReferral();
        $this->resourceId = $this->resourceByCode('suite-1');
        $this->otherResourceId = $this->resourceByCode('suite-2');

        $this->repository = new PdoAppointmentRepository($this->pdo, new PdoTransaction($this->pdo));
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            // History and reschedule rows are ON DELETE RESTRICT, so they go first;
            // deleting the appointment while they exist is refused by design.
            $this->pdo->exec(
                "DELETE FROM appointment_reschedules
                  WHERE appointment_id IN (SELECT id FROM appointments WHERE reference LIKE 'BK-FLOW-%')"
            );
            $this->pdo->exec(
                "DELETE FROM appointment_status_history
                  WHERE appointment_id IN (SELECT id FROM appointments WHERE reference LIKE 'BK-FLOW-%')"
            );
            $this->pdo->exec("DELETE FROM appointments WHERE reference LIKE 'BK-FLOW-%'");
            $this->pdo->exec("DELETE FROM referrals WHERE reference LIKE 'RF-FLOW-%'");

            if ($this->actorUserId > 0) {
                $this->pdo->exec('DELETE FROM users WHERE id = ' . $this->actorUserId);
            }
        }

        $this->pdo = null;
        $this->actorUserId = 0;
    }

    public function testBookingRescheduleAndCancellationMoveTheOccupancyWithTheAppointment(): void
    {
        $appointment = $this->book('BK-FLOW-01', '09:00:00', 60);

        self::assertSame(
            ['09:00:00', '09:15:00', '09:30:00', '09:45:00'],
            $this->slide($this->slotsFor($appointment->id)),
        );

        $moved = $this->repository->reschedule(
            $appointment,
            new RescheduleTarget(
                resourceId: $this->resourceId,
                startsAtUtc: self::DAY . ' 14:00:00',
                endsAtUtc: self::DAY . ' 15:00:00',
                durationMinutes: 60,
                slotStarts: $this->quanta('14:00:00', 60),
            ),
            new Actor($this->actorUserId, Role::Staff),
            'Patient requested the afternoon.',
            '2026-11-09 10:00:00',
        );

        self::assertSame(2, $moved->version);
        self::assertSame(
            ['14:00:00', '14:15:00', '14:30:00', '14:45:00'],
            $this->slide($this->slotsFor($appointment->id)),
        );

        $history = $this->pdo()->prepare(
            'SELECT from_starts_at, to_starts_at, from_resource_id, to_resource_id
               FROM appointment_reschedules WHERE appointment_id = :id'
        );
        $history->execute(['id' => $appointment->id]);
        $row = $history->fetch();

        self::assertIsArray($row);
        self::assertSame(self::DAY . ' 09:00:00', self::text($row['from_starts_at'] ?? null));
        self::assertSame(self::DAY . ' 14:00:00', self::text($row['to_starts_at'] ?? null));
        self::assertSame($this->resourceId, self::number($row['from_resource_id'] ?? null));
        self::assertSame($this->resourceId, self::number($row['to_resource_id'] ?? null));

        $cancelled = $this->repository->transition(
            $moved,
            AppointmentStatus::Cancelled,
            new Actor($this->actorUserId, Role::Staff),
            'Patient withdrew.',
            '2026-11-09 11:00:00',
        );

        self::assertSame(AppointmentStatus::Cancelled, $cancelled->status);
        self::assertSame([], $this->slotsFor($appointment->id));
    }

    public function testAFailedRescheduleLeavesTheOriginalBookingUntouchedInTheDatabase(): void
    {
        $appointment = $this->book('BK-FLOW-02', '09:00:00', 60);
        $this->book('BK-FLOW-03', '15:00:00', 60);

        try {
            $this->repository->reschedule(
                $appointment,
                new RescheduleTarget(
                    resourceId: $this->resourceId,
                    startsAtUtc: self::DAY . ' 15:00:00',
                    endsAtUtc: self::DAY . ' 16:00:00',
                    durationMinutes: 60,
                    slotStarts: $this->quanta('15:00:00', 60),
                ),
                new Actor($this->actorUserId, Role::Staff),
                null,
                '2026-11-09 10:00:00',
            );
            self::fail('A reschedule into an occupied slot committed.');
        } catch (SlotUnavailableException) {
            $reloaded = $this->repository->findById($appointment->id);

            self::assertNotNull($reloaded);
            self::assertSame(1, $reloaded->version);
            self::assertSame(self::DAY . ' 09:00:00', $reloaded->startsAtUtc);
            self::assertSame(
                ['09:00:00', '09:15:00', '09:30:00', '09:45:00'],
                $this->slide($this->slotsFor($appointment->id)),
            );

            $history = $this->pdo()->prepare(
                'SELECT COUNT(*) FROM appointment_reschedules WHERE appointment_id = :id'
            );
            $history->execute(['id' => $appointment->id]);
            self::assertSame(0, (int) $history->fetchColumn());
        }
    }

    public function testMovingToAnotherRoomReleasesTheFirstOneEntirely(): void
    {
        $appointment = $this->book('BK-FLOW-04', '11:00:00', 30);

        $moved = $this->repository->reschedule(
            $appointment,
            new RescheduleTarget(
                resourceId: $this->otherResourceId,
                startsAtUtc: self::DAY . ' 11:00:00',
                endsAtUtc: self::DAY . ' 11:30:00',
                durationMinutes: 30,
                slotStarts: $this->quanta('11:00:00', 30),
            ),
            new Actor($this->actorUserId, Role::Staff),
            'Suite 1 is out of service.',
            '2026-11-09 10:00:00',
        );

        self::assertSame($this->otherResourceId, $this->repository->primaryResourceFor($moved->id));
        self::assertSame([], $this->slotsFor($appointment->id, $this->resourceId));
        self::assertCount(2, $this->slotsFor($appointment->id, $this->otherResourceId));
    }

    public function testARescheduleHeldByAnotherWriterIsRefused(): void
    {
        $appointment = $this->book('BK-FLOW-05', '09:00:00', 60);

        // A colleague moved it first; this caller is still holding version 1.
        $this->repository->transition(
            $appointment,
            AppointmentStatus::Confirmed,
            new Actor($this->actorUserId, Role::Staff),
            null,
            '2026-11-09 09:30:00',
        );

        $this->expectException(AppointmentTransitionException::class);

        $this->repository->reschedule(
            $appointment,
            new RescheduleTarget(
                resourceId: $this->resourceId,
                startsAtUtc: self::DAY . ' 13:00:00',
                endsAtUtc: self::DAY . ' 14:00:00',
                durationMinutes: 60,
                slotStarts: $this->quanta('13:00:00', 60),
            ),
            new Actor($this->actorUserId, Role::Staff),
            null,
            '2026-11-09 10:00:00',
        );
    }

    private function book(string $reference, string $time, int $duration): Appointment
    {
        return $this->repository->book(
            new Appointment(
                id: 0,
                reference: $reference,
                referralId: $this->referralId,
                status: AppointmentStatus::Scheduled,
                version: 1,
                startsAtUtc: self::DAY . ' ' . $time,
                endsAtUtc: self::DAY . ' ' . $this->addMinutes($time, $duration),
                durationMinutes: $duration,
                patientName: 'Flow Fixture',
                patientPhone: '+251911000000',
                createdByUserId: $this->actorUserId,
                createdAtUtc: '2026-11-09 08:00:00',
            ),
            $this->resourceId,
            $this->quanta($time, $duration),
            new Actor($this->actorUserId, Role::Staff),
        );
    }

    /** @return list<string> */
    private function quanta(string $time, int $duration): array
    {
        $starts = [];

        for ($offset = 0; $offset < $duration; $offset += 15) {
            $starts[] = self::DAY . ' ' . $this->addMinutes($time, $offset);
        }

        return $starts;
    }

    private function addMinutes(string $time, int $minutes): string
    {
        [$hours, $mins] = array_map('intval', explode(':', $time));
        $total = $hours * 60 + $mins + $minutes;

        return str_pad((string) intdiv($total, 60), 2, '0', STR_PAD_LEFT)
            . ':' . str_pad((string) ($total % 60), 2, '0', STR_PAD_LEFT)
            . ':00';
    }

    /** @return list<string> */
    private function slotsFor(int $appointmentId, ?int $resourceId = null): array
    {
        $sql = 'SELECT slot_start FROM resource_slots WHERE appointment_id = :id';
        $parameters = ['id' => $appointmentId];

        if ($resourceId !== null) {
            $sql .= ' AND resource_id = :resource_id';
            $parameters['resource_id'] = $resourceId;
        }

        $sql .= ' ORDER BY slot_start';

        $statement = $this->pdo()->prepare($sql);
        $statement->execute($parameters);

        $column = $statement->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_map(static fn (mixed $value): string => self::text($value), $column));
    }

    /**
     * @param list<string> $slots
     *
     * @return list<string>
     */
    private function slide(array $slots): array
    {
        return array_values(array_map(static fn (string $slot): string => substr($slot, 11), $slots));
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function resourceByCode(string $code): int
    {
        $statement = $this->pdo()->prepare('SELECT id FROM resources WHERE code = :code LIMIT 1');
        $statement->execute(['code' => $code]);
        $value = $statement->fetchColumn();

        if (!is_numeric($value)) {
            self::markTestSkipped('The seeded ' . $code . ' resource is missing.');
        }

        return (int) $value;
    }

    private function insertUser(): int
    {
        $statement = $this->pdo()->prepare(
            "INSERT INTO users (email, name, password_hash, role, is_active, auth_version, created_at, updated_at)
             VALUES (:email, 'Flow Fixture', :hash, 'staff', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $statement->execute([
            'email' => 'flow-' . bin2hex(random_bytes(4)) . '@example.test',
            'hash' => password_hash('flow-fixture-not-a-login', PASSWORD_DEFAULT),
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function insertReferral(): int
    {
        $statement = $this->pdo()->prepare(
            "INSERT INTO referrals
                (reference, status, patient_name, patient_phone, procedure_code,
                 submitted_by_user_id, submitted_on_behalf_of, created_at, updated_at)
             VALUES
                ('RF-FLOW-01', 'approved_for_scheduling', 'Flow Fixture', '+251911000000',
                 'image_guided_biopsy', :user_id, 'referrer', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $statement->execute(['user_id' => $this->actorUserId]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('No database connection.');
        }

        return $this->pdo;
    }
}
