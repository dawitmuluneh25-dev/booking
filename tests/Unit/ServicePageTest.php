<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\ServiceController;
use App\Domain\ContentStatus;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\PasswordHasher;
use App\Support\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\ContentFactory;
use Tests\Support\InMemoryContentRepository;
use Tests\Support\InMemoryUserRepository;

/**
 * The pages a patient sees (§17), rendered through the real templates.
 *
 * The controller is given a fixed "today" so the assertions do not depend on the
 * day the suite runs — a review date that passes during the lifetime of a test is
 * a failure nobody can reproduce.
 */
final class ServicePageTest extends TestCase
{
    private const TODAY = '2026-11-13';

    private InMemoryContentRepository $content;

    protected function setUp(): void
    {
        $_SESSION = [];

        $this->content = new InMemoryContentRepository();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAPatientCanOpenTheServiceIndexWithoutSigningIn(): void
    {
        $response = $this->controller()->index(Request::create('GET', '/services'));

        // A redirect would mean the page wanted a session, which would defeat the
        // point of a patient information page.
        self::assertNull($response->header('Location'));
        self::assertStringContainsString('Services', $response->body());
    }

    public function testWithNothingPublishedTheIndexSaysSoInsteadOfInventingPages(): void
    {
        // The state today: four complete drafts with no reviewer and no dates. The
        // index must not list them, and must not invent a placeholder page either.
        $this->content->seed(ContentFactory::content());

        $body = $this->controller()->index(Request::create('GET', '/services'))->body();

        self::assertStringContainsString('No service pages are available yet', $body);
        self::assertStringNotContainsString('/services/fnac', $body);
    }

    public function testAPublishedPageAppearsInTheIndex(): void
    {
        $this->content->seed(ContentFactory::published());

        $body = $this->controller()->index(Request::create('GET', '/services'))->body();

        self::assertStringContainsString('/services/fnac', $body);
        self::assertStringContainsString('Image-Guided Fine Needle Aspiration Cytology (FNAC)', $body);
        self::assertStringNotContainsString('No service pages are available yet', $body);
    }

    public function testAPublishedPageRendersItsWordsAndItsProvenance(): void
    {
        $this->content->seed(ContentFactory::published());

        $body = $this->controller()->show(Request::create('GET', '/services/fnac'), ['slug' => 'fnac'])->body();

        self::assertStringContainsString('What it is', $body);
        self::assertStringContainsString('A sampling procedure.', $body);
        // §17.5: the approver appears with the page, and the review date with it as a
        // document-control field rather than as a second sentence (D-01).
        self::assertStringContainsString('Dawit Muluneh', $body);
        self::assertStringContainsString('13 November 2027', $body);
        self::assertStringContainsString('Next review', $body);
        self::assertStringContainsString('Version 1.0', $body);
    }

    public function testADraftIsIndistinguishableFromAPageThatNeverExisted(): void
    {
        $this->content->seed(ContentFactory::content());

        $this->expectException(HttpException::class);

        $this->controller()->show(Request::create('GET', '/services/fnac'), ['slug' => 'fnac']);
    }

    public function testAnExpiredPageIsNotServed(): void
    {
        $this->content->seed(ContentFactory::published('service.fnac', ['reviewOn' => '2026-11-12']));

        $this->expectException(HttpException::class);

        $this->controller()->show(Request::create('GET', '/services/fnac'), ['slug' => 'fnac']);
    }

    public function testAWithdrawnPageIsNotServed(): void
    {
        $this->content->seed(ContentFactory::published('service.fnac', [
            'status' => ContentStatus::Withdrawn,
            'withdrawnAtUtc' => '2026-11-12 09:00:00',
            'withdrawnByUserId' => 1,
        ]));

        $this->expectException(HttpException::class);

        $this->controller()->show(Request::create('GET', '/services/fnac'), ['slug' => 'fnac']);
    }

    public function testThePageEscapesWhatTheContentRecordContains(): void
    {
        $body = json_encode([
            ['heading' => 'What it is', 'paragraphs' => ['<script>alert(1)</script>']],
        ], JSON_THROW_ON_ERROR);

        $this->content->seed(ContentFactory::published('service.fnac', [
            'body' => $body,
            'title' => '<em>Not markup</em>',
        ]));

        $rendered = $this->controller()->show(Request::create('GET', '/services/fnac'), ['slug' => 'fnac'])->body();

        self::assertStringNotContainsString('<script>alert(1)</script>', $rendered);
        self::assertStringContainsString('&lt;script&gt;', $rendered);
        self::assertStringNotContainsString('<em>Not markup</em>', $rendered);
    }

    public function testAFutureEffectiveDateKeepsThePageOffTheSiteUntilItApplies(): void
    {
        $this->content->seed(ContentFactory::published('service.fnac', ['effectiveOn' => '2026-12-01']));

        $this->expectException(HttpException::class);

        $this->controller()->show(Request::create('GET', '/services/fnac'), ['slug' => 'fnac']);
    }

    private function controller(): ServiceController
    {
        $auth = new Auth(new InMemoryUserRepository(), new PasswordHasher());

        return new ServiceController(
            new View(dirname(__DIR__, 2) . '/templates'),
            $auth,
            $this->content,
            self::TODAY,
        );
    }
}
