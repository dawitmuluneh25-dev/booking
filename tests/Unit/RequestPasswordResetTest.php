<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\NotificationChannel;
use App\Domain\NotificationTemplate;
use App\Domain\RequestPasswordReset;
use App\Domain\Role;
use App\Security\Auth;
use App\Security\PasswordHasher;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryOutboxRepository;
use Tests\Support\InMemoryUserRepository;

/**
 * A reset link leaves through the outbox, once, addressed to the account holder.
 *
 * The assertions that matter are about what a stranger cannot learn and what the
 * database keeps. The link works — the raw token in the email is the token the
 * reset form will accept, which is the whole point and the easiest thing to break
 * by storing the hash in the wrong place.
 */
final class RequestPasswordResetTest extends TestCase
{
    private const BASE_URL = 'https://clinic.test';

    private InMemoryUserRepository $users;

    private InMemoryOutboxRepository $outbox;

    private Auth $auth;

    private RequestPasswordReset $request;

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

        $this->auth = new Auth($this->users, new PasswordHasher());
        $this->outbox = new InMemoryOutboxRepository();
        $this->request = new RequestPasswordReset($this->auth, $this->outbox, self::BASE_URL);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheEmailedLinkCarriesATokenTheResetFormAccepts(): void
    {
        $this->request->execute('clerk@example.test');

        $messages = $this->outbox->recent(10);

        self::assertCount(1, $messages);
        self::assertSame(NotificationChannel::Email, $messages[0]->channel);
        self::assertSame(NotificationTemplate::PasswordReset, $messages[0]->template);
        self::assertSame('clerk@example.test', $messages[0]->recipient);

        $body = $messages[0]->body(new DateTimeZone('UTC'));

        self::assertSame(1, preg_match('#(/password/reset/[0-9a-f]{64})#', $body, $matches), $body);

        $match = $matches[1] ?? '';
        $token = substr($match, strlen('/password/reset/'));

        self::assertSame(64, strlen($token), 'The body did not contain a token: ' . $body);
        self::assertTrue($this->auth->resetTokenIsValid($token), 'The emailed token is not valid.');
    }

    public function testTheMessageNamesWhenTheLinkStopsWorking(): void
    {
        $this->request->execute('clerk@example.test');

        $body = $this->outbox->recent(1)[0]->body(new DateTimeZone('UTC'));

        self::assertStringContainsString('expires at', $body);
        self::assertStringContainsString(self::BASE_URL, $body);
    }

    /**
     * The token is a credential. It belongs in the body, which the dispatcher
     * scrubs after sending, and nowhere else — least of all in a dedupe key that
     * operators read out of a table.
     */
    public function testTheTokenDoesNotAppearOutsideTheBody(): void
    {
        $this->request->execute('clerk@example.test');

        $message = $this->outbox->recent(1)[0];
        $body = $message->body(new DateTimeZone('UTC'));

        self::assertSame(1, preg_match('#/password/reset/([0-9a-f]{64})#', $body, $matches));
        $token = $matches[1] ?? '';
        self::assertSame(64, strlen($token));

        self::assertNotNull($message->dedupeKey);
        self::assertStringNotContainsString($token, $message->dedupeKey);
    }

    public function testAnAddressWithNoAccountQueuesNothing(): void
    {
        $this->request->execute('nobody@example.test');

        self::assertCount(0, $this->outbox->recent(10));
    }

    /**
     * ADR-016: a patient account is a record, not a login. Issuing it a credential
     * would give it the sign-in the model says it does not have.
     */
    public function testAPatientAccountCannotBeSentAResetLink(): void
    {
        $this->users->seed(
            'patient@example.test',
            'Patient',
            Role::Patient,
            (new PasswordHasher())->hash('correct horse battery'),
        );

        $this->request->execute('patient@example.test');

        self::assertCount(0, $this->outbox->recent(10));
    }

    public function testTwoRequestsQueueTwoLinks(): void
    {
        $this->request->execute('clerk@example.test');
        $this->request->execute('clerk@example.test');

        // A second request is a second credential. Collapsing it into the first
        // would drop a link somebody actually asked for because they never got the
        // one before it.
        self::assertCount(2, $this->outbox->recent(10));
    }
}
