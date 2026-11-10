<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AdminResourceController;
use App\Domain\AvailabilityWindow;
use App\Domain\Permission;
use App\Domain\Resource;
use App\Domain\ResourceBlock;
use App\Domain\Role;
use App\Domain\ScheduleBoard;
use App\Http\HttpException;
use App\Http\Request;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\PasswordHasher;
use App\Support\View;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryResourceAdminRepository;
use Tests\Support\InMemoryResourceBlockRepository;
use Tests\Support\InMemoryScheduleRepository;
use Tests\Support\InMemoryUserRepository;

/**
 * Closures, from both ends: the picker must not offer them, and the admin form
 * must record them in the clinic's clock rather than the server's.
 */
final class ResourceBlockTest extends TestCase
{
    private const ZONE = 'Africa/Addis_Ababa';

    private InMemoryScheduleRepository $schedule;

    private InMemoryResourceBlockRepository $blocks;

    private InMemoryResourceAdminRepository $admin;

    protected function setUp(): void
    {
        $_SESSION = [];

        $this->schedule = new InMemoryScheduleRepository();
        $this->schedule->addResource(new Resource(1, 'suite-1', 'IR Suite 1', 'suite', 'CT', true));
        $this->schedule->setWindows(1, [$this->window('08:00', '12:00')]);
        $this->blocks = new InMemoryResourceBlockRepository();
        $this->admin = new InMemoryResourceAdminRepository();
        $this->admin->seed(new Resource(1, 'suite-1', 'IR Suite 1', 'suite', 'CT', true));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAClosureIsNotOfferedAndItsNeighboursAre(): void
    {
        $board = new ScheduleBoard($this->schedule, $this->blocks);

        $before = $this->labels($board->freeSlots(1, $this->day(), $this->zone(), 60, $this->now()));

        self::assertContains('08:00', $before);
        self::assertContains('09:00', $before);

        // 09:00 to 10:00 is closed; 08:00 finishes exactly as it starts, so it stays.
        $this->blocks->seed($this->block('2026-11-12 06:00:00', '2026-11-12 07:00:00', 'Equipment service'));

        $after = $this->labels($board->freeSlots(1, $this->day(), $this->zone(), 60, $this->now()));

        self::assertContains('08:00', $after, 'A slot that ends as the closure begins is still bookable.');
        self::assertNotContains('09:00', $after);
        self::assertNotContains('09:45', $after);
        self::assertContains('10:00', $after);
    }

    public function testAClosureThatStartsOffTheQuarterHourAlsoClosesTheEarlierStart(): void
    {
        $board = new ScheduleBoard($this->schedule, $this->blocks);

        // 09:05 local (06:05 UTC) to 09:50. A 09:00 start would run into it, so
        // rounding the window outwards is what makes the offer honest.
        $this->blocks->seed($this->block('2026-11-12 06:05:00', '2026-11-12 06:50:00', 'Emergency repair'));

        $after = $this->labels($board->freeSlots(1, $this->day(), $this->zone(), 60, $this->now()));

        self::assertNotContains('09:00', $after, 'A 09:00 start would run into a 09:05 closure.');
        self::assertContains('10:00', $after);
    }

    public function testTheAdminFormStoresTheClosureInUtc(): void
    {
        $controller = $this->controller();

        $response = $controller->update(Request::create('POST', '/admin/resources', [], [
            Csrf::FIELD => Csrf::token(),
            'action' => 'add_block',
            'resource_id' => '1',
            'starts_at' => '2026-11-12T09:00',
            'ends_at' => '2026-11-12T10:30',
            'reason' => 'CT tube replacement',
        ]));

        self::assertSame(303, $response->status);

        $stored = $this->blocks->upcoming(1, '2026-11-01 00:00:00');
        self::assertCount(1, $stored);
        self::assertSame('2026-11-12 06:00:00', $stored[0]->startsAtUtc);
        self::assertSame('2026-11-12 07:30:00', $stored[0]->endsAtUtc);
        self::assertSame('CT tube replacement', $stored[0]->reason);
    }

    public function testAClosureThatEndsBeforeItStartsIsRefused(): void
    {
        $controller = $this->controller();

        $response = $controller->update(Request::create('POST', '/admin/resources', [], [
            Csrf::FIELD => Csrf::token(),
            'action' => 'add_block',
            'resource_id' => '1',
            'starts_at' => '2026-11-12T11:00',
            'ends_at' => '2026-11-12T10:00',
            'reason' => 'Typo',
        ]));

        self::assertSame(422, $response->status);
        self::assertSame([], $this->blocks->upcoming(1, '2026-11-01 00:00:00'));
    }

    public function testAClosureWithoutAReasonIsRefused(): void
    {
        $controller = $this->controller();

        $response = $controller->update(Request::create('POST', '/admin/resources', [], [
            Csrf::FIELD => Csrf::token(),
            'action' => 'add_block',
            'resource_id' => '1',
            'starts_at' => '2026-11-12T09:00',
            'ends_at' => '2026-11-12T10:00',
            'reason' => '   ',
        ]));

        self::assertSame(422, $response->status);
        self::assertSame([], $this->blocks->upcoming(1, '2026-11-01 00:00:00'));
    }

    public function testARequestWithoutACsrfTokenIsRejected(): void
    {
        $this->expectException(HttpException::class);

        $this->controller()->update(Request::create('POST', '/admin/resources', [], [
            'action' => 'add_block',
        ]));
    }

    public function testAddingARoomFromTheAdminFormStoresItAndShowsTheEmptyWeek(): void
    {
        $response = $this->controller()->store(Request::create('POST', '/admin/resources/create', [], [
            Csrf::FIELD => Csrf::token(),
            'code' => 'suite-9',
            'name' => 'IR Suite 9',
            'kind' => 'suite',
            'modality' => 'Fluoroscopy',
        ]));

        self::assertSame(303, $response->status);

        $codes = array_map(static fn ($resource): string => $resource->code, $this->admin->all());
        self::assertContains('suite-9', $codes);

        // The room exists but has no hours, so the page says so rather than
        // pretending it is bookable.
        $page = $this->controller()->index(Request::create('GET', '/admin/resources'));
        self::assertStringContainsString('IR Suite 9', $page->body());
        self::assertStringContainsString('Weekly opening hours', $page->body());
    }

    public function testADuplicateCodeIsReportedOnTheField(): void
    {
        $response = $this->controller()->store(Request::create('POST', '/admin/resources/create', [], [
            Csrf::FIELD => Csrf::token(),
            'code' => 'suite-1',
            'name' => 'Duplicate',
            'kind' => 'suite',
        ]));

        self::assertSame(422, $response->status);
        self::assertStringContainsString('already uses that code', $response->body());
    }

    public function testAWeekIsSavedPerWeekday(): void
    {
        $response = $this->controller()->saveWeek(
            Request::create('POST', '/admin/resources/1/week', [], [
                Csrf::FIELD => Csrf::token(),
                'week_2_opens' => '08:00',
                'week_2_closes' => '12:00',
            ]),
            ['id' => '1'],
        );

        self::assertSame(303, $response->status);

        $week = $this->admin->defaultWeek(1);
        self::assertSame(['opens' => '08:00', 'closes' => '12:00'], $week[2]);
        self::assertNull($week[3]);
    }

    public function testADayWithOnlyOneTimeIsTreatedAsClosed(): void
    {
        $this->controller()->saveWeek(
            Request::create('POST', '/admin/resources/1/week', [], [
                Csrf::FIELD => Csrf::token(),
                'week_2_opens' => '08:00',
                'week_2_closes' => '',
            ]),
            ['id' => '1'],
        );

        self::assertNull($this->admin->defaultWeek(1)[2]);
    }
    private function controller(): AdminResourceController
    {
        $users = new InMemoryUserRepository();
        $users->seed('admin@example.test', 'Admin', Role::Admin, (new PasswordHasher())->hash('correct horse'));
        $auth = new Auth($users, new PasswordHasher());
        $auth->attempt('admin@example.test', 'correct horse', '127.0.0.1');

        return new AdminResourceController(
            new View(dirname(__DIR__, 2) . '/templates'),
            $auth,
            new Gate($auth),
            $this->schedule,
            $this->blocks,
            $this->admin,
            self::ZONE,
        );
    }

    private function block(string $startsAtUtc, string $endsAtUtc, string $reason): ResourceBlock
    {
        return new ResourceBlock(
            id: 0,
            resourceId: 1,
            startsAtUtc: $startsAtUtc,
            endsAtUtc: $endsAtUtc,
            reason: $reason,
            createdByUserId: 1,
            createdAtUtc: '2026-11-10 08:00:00',
        );
    }

    private function window(string $opens, string $closes): AvailabilityWindow
    {
        // 2026-11-12 is a Thursday, and the fixture only opens on Thursdays.
        return new AvailabilityWindow(4, $opens, $closes);
    }

    private function day(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-12 00:00:00', $this->zone());
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-10 08:00:00', new DateTimeZone('UTC'));
    }

    private function zone(): DateTimeZone
    {
        return new DateTimeZone(self::ZONE);
    }

    /**
     * @param list<array{utc: string, label: string}> $slots
     *
     * @return list<string>
     */
    private function labels(array $slots): array
    {
        return array_map(static fn (array $slot): string => $slot['label'], $slots);
    }
}
