<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AdminPhysicianController;
use App\Domain\PhysicianProfile;
use App\Domain\PhysicianStatus;
use App\Domain\Role;
use App\Http\HttpException;
use App\Http\Request;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\PasswordHasher;
use App\Support\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryPhysicianProfileRepository;
use Tests\Support\InMemoryUserRepository;
use Tests\Support\InlineTransaction;
use Tests\Support\RecordingAuditTrail;

/**
 * The queue, rendered through the real template.
 *
 * Verification is the act that decides whether a clinician's referrals are
 * accepted, so the screen has to show the evidence it is asking somebody to judge,
 * and every refusal has to be a sentence rather than a silent no-op.
 */
final class AdminPhysicianTest extends TestCase
{
    private const NOW = '2026-11-10 08:00:00';

    private AdminPhysicianController $controller;

    private InMemoryPhysicianProfileRepository $profiles;

    private int $adminId;

    private int $physicianUserId;

    protected function setUp(): void
    {
        $_SESSION = [];

        $users = new InMemoryUserRepository();
        $this->adminId = $users->seed(
            'admin@example.test',
            'Ada Admin',
            Role::Admin,
            (new PasswordHasher())->hash('correct horse'),
        );
        $this->physicianUserId = $users->seed(
            'dr.bekele@example.test',
            'Dr Selam Bekele',
            Role::Physician,
            'unused',
        );

        $auth = new Auth($users, new PasswordHasher());
        $auth->attempt('admin@example.test', 'correct horse', '127.0.0.1');

        $this->profiles = new InMemoryPhysicianProfileRepository([
            $this->physicianUserId => ['name' => 'Dr Selam Bekele', 'email' => 'dr.bekele@example.test'],
        ]);

        $this->profiles->create(
            new PhysicianProfile(
                id: 0,
                userId: $this->physicianUserId,
                practiceNumber: 'PRAC-7781',
                institution: 'St Gabriel Referral Hospital',
                specialty: 'General surgery',
                status: PhysicianStatus::Pending,
                verifiedByUserId: null,
                verifiedAtUtc: null,
                reviewNote: null,
                createdAtUtc: self::NOW,
            ),
            self::NOW,
        );

        $this->controller = new AdminPhysicianController(
            new View(dirname(__DIR__, 2) . '/templates'),
            new Gate($auth),
            $this->profiles,
            new \App\Domain\VerifyPhysician($this->profiles, new RecordingAuditTrail(), new InlineTransaction()),
            'Africa/Addis_Ababa',
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheQueueShowsTheCredentialsSomebodyIsBeingAskedToJudge(): void
    {
        $page = $this->controller->index(Request::create('GET', '/admin/physicians'))->body();

        self::assertStringContainsString('Dr Selam Bekele', $page);
        self::assertStringContainsString('PRAC-7781', $page);
        self::assertStringContainsString('St Gabriel Referral Hospital', $page);
        self::assertStringContainsString('General surgery', $page);
        // Submitted at 08:00 UTC is 11:00 in Addis; the reviewer judges in clinic time.
        self::assertStringContainsString('10 Nov 2026, 11:00', $page);
        self::assertStringNotContainsString('08:00 UTC', $page);
    }

    public function testVerifyingMovesTheProfileOutOfThePendingQueue(): void
    {
        $response = $this->controller->verify(
            $this->post('/verify', ['note' => 'Checked against the register.']),
            ['id' => '1'],
        );

        self::assertSame(303, $response->status);
        self::assertSame(PhysicianStatus::Verified, $this->statusOf(1));

        $pending = $this->controller->index(Request::create('GET', '/admin/physicians'))->body();
        self::assertStringContainsString('No physician is waiting for verification', $pending);

        $verified = $this->controller
            ->index(Request::create('GET', '/admin/physicians', ['status' => 'verified']))
            ->body();
        self::assertStringContainsString('Dr Selam Bekele', $verified);
    }

    public function testSuspendingAProfileThatWasVerifiedRemovesTheAbilityToSubmit(): void
    {
        $this->controller->verify($this->post('/verify'), ['id' => '1']);

        $this->controller->suspend(
            $this->post('/suspend', ['note' => 'Practice number could not be confirmed.']),
            ['id' => '1'],
        );

        self::assertSame(PhysicianStatus::Suspended, $this->statusOf(1));
    }

    public function testARejectionWithoutAReasonIsRefusedAndNothingMoves(): void
    {
        $response = $this->controller->reject($this->post('/reject', ['note' => '']), ['id' => '1']);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('at least ten characters', $response->body());
        self::assertSame(PhysicianStatus::Pending, $this->statusOf(1));
    }

    public function testAnAdministratorCannotVerifyTheirOwnProfile(): void
    {
        // The domain refuses this; the screen has to say so rather than show a 500
        // or, worse, accept it.
        $this->profiles->create(
            new PhysicianProfile(
                id: 0,
                userId: $this->adminId,
                practiceNumber: 'PRAC-0001',
                institution: 'St Gabriel Referral Hospital',
                specialty: null,
                status: PhysicianStatus::Pending,
                verifiedByUserId: null,
                verifiedAtUtc: null,
                reviewNote: null,
                createdAtUtc: self::NOW,
            ),
            self::NOW,
        );

        $this->expectException(HttpException::class);

        $this->controller->verify($this->post('/verify'), ['id' => '2']);
    }

    public function testADecisionWithoutACsrfTokenIsRefused(): void
    {
        $this->expectException(HttpException::class);

        $this->controller->verify(
            Request::create('POST', '/admin/physicians/1/verify', [], ['note' => 'no token']),
            ['id' => '1'],
        );
    }

    private function statusOf(int $id): PhysicianStatus
    {
        $profile = $this->profiles->findById($id);
        self::assertInstanceOf(PhysicianProfile::class, $profile);

        return $profile->status;
    }

    /** @param array<string, string> $extra */
    private function post(string $path, array $extra = []): Request
    {
        return Request::create('POST', '/admin/physicians/1' . $path, [], [
            Csrf::FIELD => Csrf::token(),
            ...$extra,
        ]);
    }
}
