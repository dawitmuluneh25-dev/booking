<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\EscalationReadiness;
use App\Domain\EscalationRoute;
use App\Domain\EscalationRouteKind;
use App\Domain\SeedEscalationRegister;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryEscalationRouteRepository;

/**
 * D-06's register: five categories, listed before anybody answers them.
 *
 * HR-ENG-DEC-R1-003 D-06 requires the register to contain five named route categories and, in the
 * same breath, forbids inventing contact names, numbers, addresses or URLs. Those two requirements
 * are only compatible if a category can exist empty. This pins the shape of that compromise: five
 * rows, inactive, unverified, holding nothing but the receiving role the decision names, and a
 * seeding step that is safe to run twice.
 */
final class EscalationRegisterTest extends TestCase
{
    /** D-06's table, in its order. */
    private const KEYS = [
        'PRIMARY_IR' => 'Primary receiving interventional radiologist',
        'SECONDARY_RAD' => 'Secondary radiology lead / clinical lead',
        'SURGICAL_TEAM' => 'Receiving surgical or paediatric team',
        'AFTER_HOURS' => 'Verified on-call clinician',
        'TECHNICAL_SUPPORT' => 'Engineering support lead',
    ];

    private InMemoryEscalationRouteRepository $routes;

    protected function setUp(): void
    {
        $this->routes = new InMemoryEscalationRouteRepository();
    }

    public function testTheFiveKindsAreTheFiveKeysTheDecisionNames(): void
    {
        $keys = [];

        foreach (EscalationRouteKind::cases() as $kind) {
            $keys[$kind->routeKey()] = $kind->receivingRole();
        }

        self::assertSame(self::KEYS, $keys, 'The register and D-06 do not name the same five routes.');
    }

    public function testTheThreeRequiredRoutesAreStillTheThreeProductOwnerDecisionFourRequired(): void
    {
        $required = [];

        foreach (EscalationRouteKind::cases() as $kind) {
            if ($kind->requiredForActivation()) {
                $required[] = $kind->routeKey();
            }
        }

        // D-06 restates the five categories without changing which of them gate activation, so a
        // re-reading of the decision must not quietly widen or narrow the gate.
        self::assertSame(['PRIMARY_IR', 'SECONDARY_RAD', 'SURGICAL_TEAM'], $required);
    }

    public function testSeedingCreatesFiveCategoriesThatCountTowardNothing(): void
    {
        $result = (new SeedEscalationRegister($this->routes))->execute('2026-11-23 09:00:00');

        self::assertCount(5, $result['created']);
        self::assertSame([], $result['existing']);

        $all = $this->routes->all();
        self::assertCount(5, $all);

        foreach ($all as $route) {
            self::assertFalse($route->isActive, $route->kind->routeKey() . ' was seeded active.');
            self::assertFalse($route->hasContact(), $route->kind->routeKey() . ' was seeded with a contact.');
            self::assertFalse($route->isVerified(), $route->kind->routeKey() . ' was seeded verified.');
            self::assertFalse($route->countsTowardActivation());
        }

        // The whole point of seeding empty categories: the board still says escalation is disabled,
        // and names what is missing rather than claiming a capability nobody has confirmed.
        $report = (new EscalationReadiness($this->routes))->assess();

        self::assertFalse($report->activated);
        self::assertCount(3, $report->missing);
    }

    public function testSeedingTwiceCreatesNothingAndLeavesAFilledRouteAlone(): void
    {
        // Operations has already recorded and verified a primary contact; a later run of the seeder
        // — a deploy step, a support call, somebody being thorough — must not touch it.
        $filled = $this->routes->create(new EscalationRoute(
            id: 0,
            kind: EscalationRouteKind::Primary,
            label: 'Primary receiving interventional radiologist',
            contactName: 'Dr Example',
            contactPhone: '+251911000555',
            isStaffed: true,
            isActive: true,
            verifiedByUserId: null,
            verifiedAtUtc: null,
            verificationNote: null,
            createdAtUtc: '2026-11-23 08:00:00',
        ));

        $seeder = new SeedEscalationRegister($this->routes);

        $first = $seeder->execute('2026-11-23 09:00:00');
        self::assertCount(4, $first['created']);
        self::assertSame(['PRIMARY_IR'], array_map(
            static fn (EscalationRouteKind $kind): string => $kind->routeKey(),
            $first['existing'],
        ));

        $second = $seeder->execute('2026-11-23 10:00:00');
        self::assertSame([], $second['created']);
        self::assertCount(5, $second['existing']);

        $stored = $this->routes->find($filled->id);
        self::assertNotNull($stored);
        self::assertSame('+251911000555', $stored->contactPhone);
        self::assertTrue($stored->isActive);
        self::assertTrue($stored->hasContact());
    }

    public function testARetiredRouteIsNotRecreatedAsABlankOne(): void
    {
        $result = (new SeedEscalationRegister($this->routes))->execute('2026-11-23 09:00:00');
        self::assertCount(5, $result['created']);

        $afterHours = null;

        foreach ($this->routes->all() as $route) {
            if ($route->kind === EscalationRouteKind::AfterHours) {
                $afterHours = $route;
            }
        }

        self::assertNotNull($afterHours);
        $this->routes->retire($afterHours->id, '2026-11-23 11:00:00');

        $again = (new SeedEscalationRegister($this->routes))->execute('2026-11-23 12:00:00');

        self::assertSame([], $again['created'], 'A retired route was recreated, erasing the decision to retire it.');
        self::assertCount(5, $this->routes->all());
    }
}
