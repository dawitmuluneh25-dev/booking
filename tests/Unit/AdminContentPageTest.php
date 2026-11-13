<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AdminContentController;
use App\Domain\ContentStatus;
use App\Domain\PublishContent;
use App\Domain\Role;
use App\Domain\WithdrawContent;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\PasswordHasher;
use App\Support\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\ContentFactory;
use Tests\Support\InMemoryContentRepository;
use Tests\Support\InMemoryUserRepository;

/**
 * The screen that says why a page is not showing.
 *
 * Its whole value is that a draft missing a reviewer, a page whose review date has
 * passed and a withdrawn page are told apart — from the outside all three are just
 * absent, which is exactly the ambiguity an administrator has to be able to resolve.
 */
final class AdminContentPageTest extends TestCase
{
    private const TODAY = '2026-11-13';

    private InMemoryContentRepository $content;

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

        $this->content = new InMemoryContentRepository();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAnEmptyScreenSaysHowToImportThePages(): void
    {
        $body = $this->body($this->admin()->index(Request::create('GET', '/admin/content')));

        self::assertStringContainsString('No content has been imported', $body);
        self::assertStringContainsString('content:sync', $body);
    }

    public function testADraftNamesEveryInputItIsMissing(): void
    {
        $this->content->seed(ContentFactory::content('service.fnac', [
            'clinicalReviewer' => null,
            'effectiveOn' => null,
            'reviewOn' => null,
        ]));

        $body = $this->body($this->admin()->index(Request::create('GET', '/admin/content')));

        self::assertStringContainsString('clinical reviewer', $body);
        self::assertStringContainsString('effective date', $body);
        self::assertStringContainsString('review date', $body);
        self::assertStringContainsString('Dawit Muluneh', $body, 'The approver that was supplied is not shown.');
        self::assertStringContainsString('disabled', $body, 'Publish is offered on a record that cannot be published.');
    }

    public function testAPageWhoseReviewDateHasPassedIsCalledOutRatherThanSilentlyAbsent(): void
    {
        $this->content->seed(ContentFactory::published('service.fnac', ['reviewOn' => '2026-11-12']));

        $body = $this->body($this->admin()->index(Request::create('GET', '/admin/content')));

        self::assertStringContainsString('Review date passed', $body);
        self::assertStringContainsString('0', $body);
    }

    public function testACompleteDraftIsPublishedAndTheScreenSaysSo(): void
    {
        $draft = $this->content->seed(ContentFactory::content());

        $this->admin()->publish(
            $this->post('/admin/content/' . $draft->id . '/publish'),
            ['id' => (string) $draft->id],
        );

        self::assertSame(ContentStatus::Published, $this->content->find($draft->id)?->status);

        $body = $this->body($this->admin()->index(Request::create('GET', '/admin/content')));

        self::assertStringContainsString('is now published', $body);
        self::assertStringContainsString('1 version is being shown', $body);
    }

    public function testWithdrawingShowsTheReasonThePageIsGone(): void
    {
        $page = $this->content->seed(ContentFactory::published());

        $this->admin()->withdraw(
            $this->post('/admin/content/' . $page->id . '/withdraw'),
            ['id' => (string) $page->id],
        );

        $body = $this->body($this->admin()->index(Request::create('GET', '/admin/content')));

        self::assertStringContainsString('is now withdrawn', $body);
        self::assertSame(ContentStatus::Withdrawn, $this->content->find($page->id)?->status);
    }

    public function testPublishingAnIncompleteRecordReportsWhyRatherThanFailingSilently(): void
    {
        $draft = $this->content->seed(ContentFactory::content('service.fnac', ['clinicalReviewer' => null]));

        $this->admin()->publish(
            $this->post('/admin/content/' . $draft->id . '/publish'),
            ['id' => (string) $draft->id],
        );

        $body = $this->body($this->admin()->index(Request::create('GET', '/admin/content')));

        self::assertStringContainsString('clinical reviewer', $body);
        self::assertSame(ContentStatus::Draft, $this->content->find($draft->id)?->status);
    }

    public function testAStaffAccountCannotOpenTheScreen(): void
    {
        $this->expectException(HttpException::class);

        $this->staff()->index(Request::create('GET', '/admin/content'));
    }

    private function admin(): AdminContentController
    {
        return $this->controllerFor('admin@example.test');
    }

    private function staff(): AdminContentController
    {
        return $this->controllerFor('clerk@example.test');
    }

    private function controllerFor(string $email): AdminContentController
    {
        $auth = new Auth($this->users, new PasswordHasher());
        $auth->attempt($email, 'correct horse', '127.0.0.1');

        return new AdminContentController(
            new View(dirname(__DIR__, 2) . '/templates'),
            $auth,
            new Gate($auth),
            $this->content,
            new PublishContent($this->content),
            new WithdrawContent($this->content),
            self::TODAY,
        );
    }

    /** @param array<string, string> $payload */
    private function post(string $path, array $payload = []): Request
    {
        return Request::create('POST', $path, [], $payload + [Csrf::FIELD => Csrf::token()]);
    }

    private function body(Response $response): string
    {
        return $response->body();
    }
}
