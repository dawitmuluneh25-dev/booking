<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\ForgotPasswordController;
use App\Domain\RequestPasswordReset;
use App\Domain\Role;
use App\Http\Request;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\PasswordHasher;
use App\Security\Session;
use App\Support\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryOutboxRepository;
use Tests\Support\InMemoryUserRepository;

/**
 * The public reset request form.
 *
 * The test that matters is the one that compares two runs: an address with an
 * account and an address without must produce the same page, the same status and
 * the same words. Anything that differs is a way to ask this form who works here.
 */
final class ForgotPasswordPageTest extends TestCase
{
    private InMemoryUserRepository $users;

    private InMemoryOutboxRepository $outbox;

    protected function setUp(): void
    {
        $_SESSION = [];

        $this->users = new InMemoryUserRepository();
        $this->users->seed(
            'clerk@example.test',
            'Clerk',
            Role::Staff,
            (new PasswordHasher())->hash('correct horse battery'),
        );

        $this->outbox = new InMemoryOutboxRepository();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheFormAsksForAnAddressAndNothingElse(): void
    {
        $body = $this->controller()->show(Request::create('GET', '/password/forgot'))->body();

        self::assertStringContainsString('Reset your password', $body);
        self::assertStringContainsString('name="email"', $body);
        self::assertStringNotContainsString('name="password"', $body);
    }

    public function testAKnownAndAnUnknownAddressGetTheSameAnswer(): void
    {
        $known = $this->submit('clerk@example.test');
        $unknown = $this->submit('nobody@example.test');

        self::assertSame($known->status, $unknown->status);
        self::assertSame($known->header('Location'), $unknown->header('Location'));
        self::assertSame($known->body(), $unknown->body());

        // The difference is entirely behind the form: one message was queued, the
        // other was not, and the page cannot tell.
        self::assertCount(1, $this->outbox->recent(10));
    }

    public function testTheNoticeDoesNotAdmitWhoseAddressItWas(): void
    {
        $this->submit('clerk@example.test');

        $notice = (string) Session::pullFlash('notice');

        self::assertStringContainsString('If that address has an account', $notice);
        self::assertStringNotContainsString('clerk@example.test', $notice);
    }

    public function testAMalformedAddressIsRefusedOnTheForm(): void
    {
        $response = $this->submit('not-an-address');

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Enter the email address you sign in with', $response->body());
        self::assertCount(0, $this->outbox->recent(10));
    }

    private function submit(string $email): \App\Http\Response
    {
        return $this->controller()->store(Request::create(
            'POST',
            '/password/forgot',
            [],
            ['email' => $email, Csrf::FIELD => Csrf::token()],
        ));
    }

    private function controller(): ForgotPasswordController
    {
        $auth = new Auth($this->users, new PasswordHasher());

        return new ForgotPasswordController(
            new View(dirname(__DIR__, 2) . '/templates'),
            new RequestPasswordReset($auth, $this->outbox, 'https://clinic.test'),
        );
    }
}
