<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\PractitionerRegisterController;
use App\Domain\ChangePractitionerCredentialStatus;
use App\Domain\EndPractitionerPrivilege;
use App\Domain\GrantPractitionerPrivilege;
use App\Domain\Procedure;
use App\Domain\RecordPractitionerCredential;
use App\Domain\Role;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\PasswordHasher;
use App\Support\View;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryPractitionerCredentialRepository;
use Tests\Support\InMemoryProcedureRepository;
use Tests\Support\InMemoryUserRepository;
use Tests\Support\InlineTransaction;
use Tests\Support\RecordingAuditTrail;

/**
 * The register screen (Decision 6, AC-009).
 *
 * The page is where a refusal becomes a sentence somebody can act on, so these tests
 * render the real templates: a credential that is recorded but cannot be seen is a
 * register nobody can use.
 */
final class PractitionerRegisterPageTest extends TestCase
{
    private InMemoryUserRepository $users;

    private InMemoryPractitionerCredentialRepository $credentials;

    private PractitionerRegisterController $controller;

    private int $radiologistId = 0;

    private int $clerkId = 0;

    private string $password = 'correct horse';

    protected function setUp(): void
    {
        $_SESSION = [];

        $this->users = new InMemoryUserRepository();
        $hash = (new PasswordHasher())->hash($this->password);

        $this->radiologistId = $this->users->seed(
            'lead@example.test',
            'Dr Selam Bekele',
            Role::Radiologist,
            $hash,
        );
        $this->clerkId = $this->users->seed('clerk@example.test', 'Sam Reception', Role::Staff, $hash);

        $this->credentials = new InMemoryPractitionerCredentialRepository();

        $this->signIn('lead@example.test');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheRegisterSaysWhenNoVerificationExists(): void
    {
        $body = $this->body($this->controller->index(Request::create('GET', '/practitioners')));

        self::assertStringContainsString('Dr Selam Bekele', $body);
        self::assertStringContainsString('No verification is recorded', $body);
        self::assertStringContainsString('Record a verification', $body);
        // The desk account holds no approval authority, so it is not offered.
        self::assertStringNotContainsString('Sam Reception', $body);
    }

    /** Decision 5: the administrator holds technical authority only. */
    public function testReceptionIsRefusedTheRegister(): void
    {
        $this->signIn('clerk@example.test');

        $this->expectException(HttpException::class);

        $this->controller->index(Request::create('GET', '/practitioners'));
    }

    public function testAVerificationRecordedThroughTheScreenIsListedWithItsStatus(): void
    {
        $response = $this->controller->recordCredential(
            Request::create('POST', '/practitioners/credentials', [], [
                'practitioner_id' => (string) $this->radiologistId,
                'credential_type' => 'Specialist registration',
                'registration_number' => 'ET-IR-0042',
                'evidence_reference' => 'Registration register entry checked 14 November 2026.',
                'effective_from' => '2026-01-01',
                'expires_on' => '2027-01-01',
                Csrf::FIELD => Csrf::token(),
            ]),
        );

        self::assertSame(303, $response->status);

        $body = $this->body($this->controller->index(Request::create('GET', '/practitioners')));

        self::assertStringContainsString('Specialist registration', $body);
        self::assertStringContainsString('ET-IR-0042', $body);
        self::assertStringContainsString('Verified', $body);
        self::assertStringContainsString('Current until 2027-01-01', $body);
        self::assertStringContainsString('Self-recorded', $body);
        // A credential without a privilege authorises no procedure, and the page says so.
        self::assertStringContainsString('authorises no approval yet', $body);
    }

    public function testAVerificationForTheDeskAccountIsRefusedWithAReason(): void
    {
        $response = $this->controller->recordCredential(
            Request::create('POST', '/practitioners/credentials', [], [
                'practitioner_id' => (string) $this->clerkId,
                'credential_type' => 'Specialist registration',
                'registration_number' => 'ET-IR-0099',
                'evidence_reference' => 'Register entry checked on the day.',
                'effective_from' => '2026-01-01',
                Csrf::FIELD => Csrf::token(),
            ]),
        );

        self::assertSame(422, $response->status);
        self::assertStringContainsString(
            'does not hold clinical approval authority',
            $this->body($response),
        );
        self::assertSame([], $this->credentials->forUser($this->clerkId));
    }

    public function testAPrivilegeGrantedThroughTheScreenIsListedAndCanBeEnded(): void
    {
        $this->controller->recordCredential(
            Request::create('POST', '/practitioners/credentials', [], [
                'practitioner_id' => (string) $this->radiologistId,
                'credential_type' => 'Specialist registration',
                'registration_number' => 'ET-IR-0042',
                'evidence_reference' => 'Registration register entry checked 14 November 2026.',
                'effective_from' => '2026-01-01',
                Csrf::FIELD => Csrf::token(),
            ]),
        );

        $credential = $this->credentials->forUser($this->radiologistId)[0] ?? null;
        self::assertNotNull($credential);

        $granted = $this->controller->grantPrivilege(
            Request::create('POST', '/practitioners/credentials/' . $credential->id . '/privileges', [], [
                'procedure_code' => 'image_guided_fnac',
                'basis' => 'own',
                'effective_from' => '2026-01-01',
                Csrf::FIELD => Csrf::token(),
            ]),
            ['credentialId' => (string) $credential->id],
        );

        self::assertSame(303, $granted->status);

        $body = $this->body($this->controller->index(Request::create('GET', '/practitioners')));
        self::assertStringContainsString('image_guided_fnac', $body);

        $privilege = $this->credentials->findPrivilege(1);
        self::assertNotNull($privilege);

        $ended = $this->controller->endPrivilege(
            Request::create('POST', '/practitioners/privileges/' . $privilege->id . '/end', [], [
                'ended_on' => '2026-12-31',
                Csrf::FIELD => Csrf::token(),
            ]),
            ['privilegeId' => (string) $privilege->id],
        );

        self::assertSame(303, $ended->status);
        self::assertStringContainsString('Ended', $this->body(
            $this->controller->index(Request::create('GET', '/practitioners')),
        ));
    }

    public function testASuspendAndReinstateRoundTripIsOfferedAndWorks(): void
    {
        $this->controller->recordCredential(
            Request::create('POST', '/practitioners/credentials', [], [
                'practitioner_id' => (string) $this->radiologistId,
                'credential_type' => 'Specialist registration',
                'registration_number' => 'ET-IR-0042',
                'evidence_reference' => 'Registration register entry checked 14 November 2026.',
                'effective_from' => '2026-01-01',
                Csrf::FIELD => Csrf::token(),
            ]),
        );

        $credential = $this->credentials->forUser($this->radiologistId)[0] ?? null;
        self::assertNotNull($credential);

        $this->controller->changeStatus(
            Request::create('POST', '/practitioners/credentials/' . $credential->id . '/status', [], [
                'status' => 'suspended',
                Csrf::FIELD => Csrf::token(),
            ]),
            ['credentialId' => (string) $credential->id],
        );

        $body = $this->body($this->controller->index(Request::create('GET', '/practitioners')));
        self::assertStringContainsString('Suspended', $body);
        self::assertStringContainsString('Reinstate this credential', $body);

        $this->controller->changeStatus(
            Request::create('POST', '/practitioners/credentials/' . $credential->id . '/status', [], [
                'status' => 'verified',
                Csrf::FIELD => Csrf::token(),
            ]),
            ['credentialId' => (string) $credential->id],
        );

        $stored = $this->credentials->findCredential($credential->id);
        self::assertNotNull($stored);
        self::assertSame('verified', $stored->status->value);
    }

    private function build(): PractitionerRegisterController
    {
        $auth = new Auth($this->users, new PasswordHasher());
        $gate = new Gate($auth);
        $procedures = new InMemoryProcedureRepository();
        $procedures->add(new Procedure(
            code: 'image_guided_fnac',
            label: 'Image-guided fine needle aspiration (FNAC)',
            category: 'elective',
            defaultDurationMinutes: 30,
            requiredFields: null,
            fieldSetVersion: 1,
            approvedBy: null,
            approvedAtUtc: '',
            active: true,
        ));

        $audit = new RecordingAuditTrail();
        $transaction = new InlineTransaction();

        return new PractitionerRegisterController(
            new View(dirname(__DIR__, 2) . '/templates'),
            $auth,
            $gate,
            $this->users,
            $this->credentials,
            $procedures,
            new RecordPractitionerCredential($this->credentials, $this->users, new Validator(), $transaction, $audit),
            new ChangePractitionerCredentialStatus($this->credentials, $transaction, $audit),
            new GrantPractitionerPrivilege(
                $this->credentials,
                $procedures,
                $this->users,
                new Validator(),
                $transaction,
                $audit,
            ),
            new EndPractitionerPrivilege($this->credentials, $transaction, $audit),
            'Africa/Addis_Ababa',
        );
    }

    /**
     * A fresh session and a fresh controller, because `Gate` resolves the session user
     * once per instance: reusing one controller across two accounts would leave the
     * first account's authorities in place and quietly test the wrong person.
     */
    private function signIn(string $email): void
    {
        $_SESSION = [];
        (new Auth($this->users, new PasswordHasher()))->attempt($email, $this->password, '127.0.0.1');
        $this->controller = $this->build();
    }

    private function body(Response $response): string
    {
        return $response->body();
    }
}
