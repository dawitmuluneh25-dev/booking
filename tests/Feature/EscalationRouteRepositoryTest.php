<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Domain\EscalationRoute;
use App\Domain\EscalationRouteKind;
use App\Repositories\PdoEscalationRouteRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The escalation roster against MySQL (Decision 4).
 *
 * The constraints carry the decision, so they are what this test weighs: an
 * unstaffed after-hours route cannot be verified, a verification without a note
 * cannot exist, a route is either verified by somebody at some time or by nobody.
 */
final class EscalationRouteRepositoryTest extends TestCase
{
    private const NOW = '2026-11-14 10:00:00';

    private ?PDO $pdo = null;

    private ?PdoEscalationRouteRepository $repository = null;

    private int $userId = 0;

    /** @var list<int> */
    private array $routeIds = [];

    /** This run's fixture identity; see the note in setUp. */
    private string $runId = '';

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        Env::load($root . '/.env');
        $config = new Config($root);

        try {
            $pdo = Connection::create($config->database());
        } catch (PDOException | RuntimeException $exception) {
            self::markTestSkipped('MySQL is not reachable: ' . $exception->getMessage());
        }

        $this->pdo = $pdo;

        // The probe address was a constant and tearDown deletes only by id, so a run
        // killed mid-test left a row the next one could not insert past
        // (`users.uq_users_email`). The address is a fixture, not an assertion, so it
        // can name its own run. One concatenation, and no sweep to get wrong.
        $this->runId = bin2hex(random_bytes(4));

        $this->userId = $this->insertUser();
        $this->repository = new PdoEscalationRouteRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            foreach ($this->routeIds as $id) {
                $this->pdo->exec('DELETE FROM escalation_routes WHERE id = ' . $id);
            }

            if ($this->userId > 0) {
                $this->pdo->exec('DELETE FROM users WHERE id = ' . $this->userId);
            }
        }

        $this->routeIds = [];
        $this->pdo = null;
        $this->repository = null;
    }

    public function testARouteIsStoredUnverifiedAndThenVerifiedWithItsNote(): void
    {
        $route = $this->repository()->create($this->route(EscalationRouteKind::Primary));
        $this->routeIds[] = $route->id;

        self::assertFalse($route->isVerified());

        $this->repository()->verify(
            $route->id,
            $this->userId,
            'Rang the switchboard; the duty radiologist answered and confirmed the rota.',
            self::NOW,
        );

        $stored = $this->repository()->find($route->id);
        self::assertNotNull($stored);
        self::assertTrue($stored->isVerified());
        self::assertSame($this->userId, $stored->verifiedByUserId);
        self::assertSame(self::NOW, $stored->verifiedAtUtc);
        self::assertStringContainsString('duty radiologist', (string) $stored->verificationNote);
    }

    /** Decision 4: after-hours escalation only where coverage genuinely exists. */
    public function testAnUnstaffedAfterHoursRouteCannotBeVerified(): void
    {
        // The command refuses this before it reaches SQL; the constraint is the second
        // gate, and the one a future caller cannot forget.
        $route = $this->repository()->create($this->route(EscalationRouteKind::AfterHours, staffed: false));
        $this->routeIds[] = $route->id;

        $this->expectException(PDOException::class);

        $this->repository()->verify($route->id, $this->userId, 'Called and nobody answered, but recorded.', self::NOW);
    }

    public function testAVerificationWithoutANoteIsRefusedByTheSchema(): void
    {
        $route = $this->repository()->create($this->route(EscalationRouteKind::Primary));
        $this->routeIds[] = $route->id;

        $this->expectException(PDOException::class);

        $this->repository()->verify($route->id, $this->userId, 'ok', self::NOW);
    }

    public function testRetiringKeepsTheRowAndItsVerification(): void
    {
        $route = $this->repository()->create($this->route(EscalationRouteKind::Secondary));
        $this->routeIds[] = $route->id;
        $this->repository()->verify($route->id, $this->userId, 'Confirmed with the radiology lead.', self::NOW);

        $this->repository()->retire($route->id, self::NOW);

        $stored = $this->repository()->find($route->id);
        self::assertNotNull($stored);
        self::assertFalse($stored->isActive);
        self::assertTrue($stored->isVerified());
        self::assertNotContains($route->id, array_map(
            static fn (EscalationRoute $each): int => $each->id,
            $this->repository()->active(),
        ));
    }

    public function testTheRosterIsOrderedTheWayACallerUsesIt(): void
    {
        $kinds = [
            EscalationRouteKind::ReceivingTeam,
            EscalationRouteKind::Primary,
            EscalationRouteKind::Secondary,
        ];

        $created = [];

        foreach ($kinds as $kind) {
            $route = $this->repository()->create($this->route($kind));
            $this->routeIds[] = $route->id;
            $created[] = $route->id;
        }

        // Scoped to the rows this test created. The assertion used to read the whole table, which
        // passed only while the table happened to be empty — and 0046/D-06 give every environment
        // five register categories before anybody fills one in, so an empty table is no longer the
        // normal state. The order being asserted is a property of the repository's ordering, not of
        // which routes exist.
        $ordered = [];

        foreach ($this->repository()->all() as $each) {
            if (in_array($each->id, $created, true)) {
                $ordered[] = $each->kind->value;
            }
        }

        self::assertSame(['primary', 'secondary', 'receiving_team'], $ordered);
    }

    /**
     * D-06's register categories, and the price of allowing them: inactive is the only state an
     * unfilled route may occupy.
     *
     * Written as raw SQL because the point is the constraint, not the repository. A future script
     * that inserts straight into the table is exactly the caller this CHECK exists for.
     */
    public function testAnUnfilledRouteMayExistOnlyWhileItIsInactive(): void
    {
        $label = 'placeholder ' . $this->runId;

        $this->pdo()->exec(
            "INSERT INTO escalation_routes
                (kind, label, contact_name, contact_phone, is_staffed, is_active, created_at, updated_at)
             VALUES
                ('primary', '" . $label . "', NULL, NULL, 0, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );

        $id = (int) $this->pdo()->lastInsertId();
        $this->routeIds[] = $id;

        // Inactive and empty is a legitimate state: the category exists, nobody answers it yet.
        $stored = $this->repository()->find($id);
        self::assertNotNull($stored);
        self::assertFalse($stored->isActive);
        self::assertFalse($stored->hasContact());

        try {
            $this->pdo()->exec('UPDATE escalation_routes SET is_active = 1 WHERE id = ' . $id);
            self::fail('A route with no contact was activated, so the roster can promise a line nobody answers.');
        } catch (PDOException $exception) {
            self::assertStringContainsString('ck_escalation_route_active_complete', $exception->getMessage());
        }
    }

    /** The three fields D-06 adds beside the contact survive a round trip. */
    public function testTheRegisterStoresHoursTheAcknowledgementExpectationAndTheFallback(): void
    {
        $route = new EscalationRoute(
            id: 0,
            kind: EscalationRouteKind::Secondary,
            label: 'secondary ' . $this->runId,
            contactName: 'Duty officer',
            contactPhone: '+251911000555',
            isStaffed: true,
            isActive: true,
            verifiedByUserId: null,
            verifiedAtUtc: null,
            verificationNote: null,
            createdAtUtc: self::NOW,
            operatingHours: 'Mon-Fri 08:00-17:00',
            acknowledgementExpectation: 'A clinician answers and accepts or declines the case.',
            fallbackRoute: 'Primary contact, then the technical support lead.',
        );

        $stored = $this->repository()->create($route);
        $this->routeIds[] = $stored->id;

        $read = $this->repository()->find($stored->id);

        self::assertNotNull($read);
        self::assertSame('Mon-Fri 08:00-17:00', $read->operatingHours);
        self::assertSame(
            'A clinician answers and accepts or declines the case.',
            $read->acknowledgementExpectation,
        );
        self::assertSame('Primary contact, then the technical support lead.', $read->fallbackRoute);
        self::assertTrue($read->hasContact());
    }

    private function route(EscalationRouteKind $kind, bool $staffed = true): EscalationRoute
    {
        return new EscalationRoute(
            id: 0,
            kind: $kind,
            label: $kind->value . ' route',
            contactName: 'Duty officer',
            contactPhone: '+251911000555',
            isStaffed: $staffed,
            isActive: true,
            verifiedByUserId: null,
            verifiedAtUtc: null,
            verificationNote: null,
            createdAtUtc: self::NOW,
        );
    }

    private function insertUser(): int
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO users (email, name, role, password_hash, is_active, created_at, updated_at)
             VALUES (:email, :name, :role, :hash, 1, :created_at, :updated_at)'
        );
        $statement->execute([
            'email' => 'escalation-' . $this->runId . '@example.test',
            'name' => 'Escalation Probe',
            'role' => 'admin',
            'hash' => '$2y$04$notarealhashthecolumnonlyneedswidth',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function repository(): PdoEscalationRouteRepository
    {
        $repository = $this->repository;
        self::assertInstanceOf(PdoEscalationRouteRepository::class, $repository);

        return $repository;
    }

    private function pdo(): PDO
    {
        $pdo = $this->pdo;
        self::assertInstanceOf(PDO::class, $pdo);

        return $pdo;
    }
}
