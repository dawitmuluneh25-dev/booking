<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AdminLaboratoryController;
use App\Domain\AddLaboratoryDestination;
use App\Domain\Role;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\PasswordHasher;
use App\Support\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryLaboratoryDestinationRepository;
use Tests\Support\InMemoryUserRepository;

/**
 * The laboratory configuration page, rendered through the real template.
 *
 * Two things are worth a page test rather than a command test: that a catalogue with
 * nothing in it says so instead of showing an empty table, and that the page offers
 * no way to type a name or delete a laboratory, because those are the two edits the
 * approved decision rules out.
 */
final class AdminLaboratoryPageTest extends TestCase
{
    private InMemoryLaboratoryDestinationRepository $destinations;

    private InMemoryUserRepository $users;

    protected function setUp(): void
    {
        $_SESSION = [];

        $this->users = new InMemoryUserRepository();
        $this->users->seed(
            'admin@example.test',
            'Administrator',
            Role::Admin,
            (new PasswordHasher())->hash('correct horse'),
        );
        $this->users->seed(
            'clerk@example.test',
            'Clerk',
            Role::Staff,
            (new PasswordHasher())->hash('correct horse'),
        );

        $this->destinations = new InMemoryLaboratoryDestinationRepository();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAnEmptyCatalogueSaysThereIsNowhereToSendASpecimen(): void
    {
        $body = $this->body($this->admin()->index(Request::create('GET', '/admin/laboratories')));

        self::assertStringContainsString('No laboratory is configured', $body);
        self::assertStringContainsString('Pathology Lab 1', $body, 'The next serial is not offered.');
    }

    public function testAddingOneListsItWithACodeThatMatchesItsLabel(): void
    {
        $this->admin()->store($this->post('/admin/laboratories', []));

        $body = $this->body($this->admin()->index(Request::create('GET', '/admin/laboratories')));

        self::assertStringContainsString('PATHOLOGY-LAB-1', $body);
        self::assertStringContainsString('Pathology Lab 1', $body);
        self::assertStringContainsString('Pathology Lab 2', $body, 'The next serial did not advance.');
    }

    public function testClosingALaboratoryKeepsItInTheListAndStopsItBeingOpen(): void
    {
        $this->admin()->store($this->post('/admin/laboratories', []));
        $first = $this->destinations->all()[0];

        $this->admin()->toggle(
            $this->post('/admin/laboratories/' . $first->id, []),
            ['id' => (string) $first->id],
        );

        $body = $this->body($this->admin()->index(Request::create('GET', '/admin/laboratories')));

        self::assertStringContainsString('Pathology Lab 1', $body);
        self::assertStringContainsString('closed, still named on earlier specimens', $body);
        self::assertStringContainsString('0 of 1', $body);
    }

    /**
     * The two edits the decision rules out: a typed name, and a delete.
     */
    public function testThePageOffersNoWayToNameOrDeleteALaboratory(): void
    {
        $this->admin()->store($this->post('/admin/laboratories', []));

        $body = $this->body($this->admin()->index(Request::create('GET', '/admin/laboratories')));

        self::assertStringNotContainsString('name="label"', $body);
        self::assertStringNotContainsString('name="serial_no"', $body);
        self::assertStringNotContainsString('Delete', $body);
    }

    public function testAStaffAccountCannotOpenThePage(): void
    {
        $this->expectException(HttpException::class);

        $this->staff()->index(Request::create('GET', '/admin/laboratories'));
    }

    private function admin(): AdminLaboratoryController
    {
        return $this->controllerFor('admin@example.test');
    }

    private function staff(): AdminLaboratoryController
    {
        return $this->controllerFor('clerk@example.test');
    }

    private function controllerFor(string $email): AdminLaboratoryController
    {
        $auth = new Auth($this->users, new PasswordHasher());
        $auth->attempt($email, 'correct horse', '127.0.0.1');

        return new AdminLaboratoryController(
            new View(dirname(__DIR__, 2) . '/templates'),
            $auth,
            new Gate($auth),
            $this->destinations,
            new AddLaboratoryDestination($this->destinations),
        );
    }

    /** @param array<string, string> $payload */
    private function post(string $path, array $payload): Request
    {
        return Request::create('POST', $path, [], $payload + [Csrf::FIELD => Csrf::token()]);
    }

    private function body(Response $response): string
    {
        return $response->body();
    }
}
