<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\UrgentController;
use App\Domain\AcknowledgeUrgentCase;
use App\Domain\Actor;
use App\Domain\CloseUrgentCase;
use App\Domain\EscalateUrgentCase;
use App\Domain\RecordUrgentContactAttempt;
use App\Domain\RegisterUrgentCase;
use App\Domain\Role;
use App\Domain\UrgentCase;
use App\Domain\UrgentCoordination;
use App\Domain\UrgentStatus;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\PasswordHasher;
use App\Security\Session;
use App\Support\View;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryUrgentCaseRepository;
use Tests\Support\InMemoryUserRepository;

/**
 * The urgent board and case page.
 *
 * The properties worth testing are the ones a reader would trust without checking:
 * the case nobody has picked up is at the top, a recorded case is never described
 * as acknowledged, closure needs an outcome, and the page does not pretend an
 * escalation policy exists.
 */
final class UrgentBoardPageTest extends TestCase
{
    private const NOW = '2026-11-08 13:00:00';

    private UrgentController $controller;

    private InMemoryUrgentCaseRepository $cases;

    private InMemoryUserRepository $users;

    private RegisterUrgentCase $register;

    private int $coordinatorId = 0;

    private int $staffId = 0;

    protected function setUp(): void
    {
        $_SESSION = [];

        $this->users = new InMemoryUserRepository();
        $hash = (new PasswordHasher())->hash('correct horse');

        // Seeded in creation order: the ids are what the user-name map below keys on.
        $this->users->seed('coordinator@example.test', 'Coordinator', Role::Coordinator, $hash);
        $this->users->seed('clerk@example.test', 'Clerk', Role::Staff, $hash);

        $this->coordinatorId = 1;
        $this->staffId = 2;

        $this->cases = new InMemoryUrgentCaseRepository([
            $this->coordinatorId => 'Selamawit Girma',
            $this->staffId => 'Meseret Alem',
        ]);
        $this->register = new RegisterUrgentCase($this->cases, new Validator());

        // The board is a coordinator's screen by default; individual tests sign in
        // as reception to check what reception may not do.
        $this->controller = $this->controllerFor('coordinator@example.test');
    }

    /**
     * A controller acting as one of the seeded users.
     *
     * Rebuilding it rather than swapping a property keeps each test honest about
     * which session it is running under: the Gate reads the session, so signing in
     * is the only way to change who is asking.
     */
    private function controllerFor(string $email): UrgentController
    {
        $_SESSION = [];

        $auth = new Auth($this->users, new PasswordHasher());
        $auth->attempt($email, 'correct horse', '127.0.0.1');

        return new UrgentController(
            new View(dirname(__DIR__, 2) . '/templates'),
            new Gate($auth),
            $this->cases,
            $this->register,
            new AcknowledgeUrgentCase($this->cases),
            new RecordUrgentContactAttempt($this->cases),
            new EscalateUrgentCase($this->cases),
            new CloseUrgentCase($this->cases),
            'Africa/Addis_Ababa',
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheBoardListsTheCaseAndSaysItIsNotAnAppointment(): void
    {
        $this->registerCase('Bereket Tesfaye', self::NOW);

        $body = $this->body($this->controller->index(Request::create('GET', '/urgent-cases')));

        self::assertStringContainsString('Bereket Tesfaye', $body);
        self::assertStringContainsString('14 months', $body);
        self::assertStringContainsString('not an appointment', $body);
        self::assertStringContainsString('Waiting', $body);
    }

    /**
     * LP-020 is open, so no interval exists. A page that called a case overdue would
     * be enacting a clinical response time nobody approved.
     */
    public function testTheBoardStatesThatNoEscalationPolicyIsConfigured(): void
    {
        $this->registerCase('Bereket Tesfaye', self::NOW);

        $body = $this->body($this->controller->index(Request::create('GET', '/urgent-cases')));

        self::assertStringContainsString('Escalation policy: not configured', $body);
        self::assertStringContainsString('LP-020', $body);
        // The page states the absence rather than substituting a threshold: elapsed
        // time is reported, and nothing is labelled late.
        self::assertStringContainsString('does not call a case overdue', $body);
    }

    public function testAnUnacknowledgedCaseIsListedBeforeAnOlderAcknowledgedOne(): void
    {
        $acknowledged = $this->registerCase('Older Acknowledged', '2026-11-08 06:00:00');
        $this->cases->acknowledge(
            $acknowledged,
            new Actor($this->staffId, Role::Staff),
            'application',
            null,
            '2026-11-08 06:05:00',
        );

        $this->registerCase('Newer Unacknowledged', '2026-11-08 09:00:00');

        $body = $this->body($this->controller->index(Request::create('GET', '/urgent-cases')));

        $waiting = strpos($body, 'Newer Unacknowledged');
        $claimed = strpos($body, 'Older Acknowledged');

        self::assertIsInt($waiting);
        self::assertIsInt($claimed);
        self::assertLessThan($claimed, $waiting, 'A case nobody has claimed was listed below one that was.');
    }

    public function testAClosedCaseLeavesTheBoard(): void
    {
        $case = $this->registerCase('Bereket Tesfaye', self::NOW);

        $this->controller->close(
            $this->post('/urgent-cases/' . $case->reference . '/close', [
                'resolution_note' => 'Reduced at Black Lion; admitted for observation overnight.',
            ]),
            ['reference' => $case->reference],
        );

        $body = $this->body($this->controller->index(Request::create('GET', '/urgent-cases')));

        self::assertStringContainsString('No urgent case is waiting', $body);
        self::assertSame(UrgentStatus::Closed, $this->cases->caseOrFail($case->id)->urgency);
    }

    public function testTheCasePageOffersAcknowledgementOnlyWhileNoneIsRecorded(): void
    {
        $case = $this->registerCase('Bereket Tesfaye', self::NOW);

        $before = $this->body($this->controller->show(
            Request::create('GET', '/urgent-cases/' . $case->reference),
            ['reference' => $case->reference],
        ));

        self::assertStringContainsString('Acknowledge case', $before);
        self::assertStringContainsString('No acknowledgement has been recorded', $before);

        $this->controller->acknowledge(
            $this->post('/urgent-cases/' . $case->reference . '/acknowledge', [
                'channel' => 'telephone',
                'note' => 'Called the referring hospital; child is on the way.',
            ]),
            ['reference' => $case->reference],
        );

        $after = $this->body($this->controller->show(
            Request::create('GET', '/urgent-cases/' . $case->reference),
            ['reference' => $case->reference],
        ));

        self::assertStringNotContainsString('Acknowledge case', $after);
        self::assertStringContainsString('Selamawit Girma', $after);
        self::assertStringContainsString('Acknowledged — telephone', $after);
    }

    public function testTheAcknowledgementIsNotDescribedAsClinicalAcceptance(): void
    {
        $case = $this->registerCase('Bereket Tesfaye', self::NOW);

        $this->controller->acknowledge(
            $this->post('/urgent-cases/' . $case->reference . '/acknowledge', ['channel' => 'telephone']),
            ['reference' => $case->reference],
        );

        $body = $this->body($this->controller->show(
            Request::create('GET', '/urgent-cases/' . $case->reference),
            ['reference' => $case->reference],
        ));

        self::assertStringContainsString('not the same as a clinical decision', $body);
        self::assertSame(UrgentStatus::Received, $this->cases->caseOrFail($case->id)->urgency);
    }

    public function testClosureWithoutAnOutcomeIsRefusedAndChangesNothing(): void
    {
        $case = $this->registerCase('Bereket Tesfaye', self::NOW);

        $response = $this->controller->close(
            $this->post('/urgent-cases/' . $case->reference . '/close', ['resolution_note' => 'done']),
            ['reference' => $case->reference],
        );

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Record what happened to this patient', $this->body($response));
        self::assertSame(UrgentStatus::Received, $this->cases->caseOrFail($case->id)->urgency);
    }

    /**
     * `urgent.coordinate` opens the route; the coordination table still decides who
     * may acknowledge. Reception holds the first and appears in the second, so the
     * form is shown — but a patient does not, and the domain refuses them.
     */
    public function testAStaffMemberMayAcknowledgeButNotEscalate(): void
    {
        $case = $this->registerCase('Bereket Tesfaye', self::NOW);
        $staffController = $this->controllerFor('clerk@example.test');

        // Reception may open the case and claim it.
        $body = $this->body($staffController->show(
            Request::create('GET', '/urgent-cases/' . $case->reference),
            ['reference' => $case->reference],
        ));
        self::assertStringContainsString('Acknowledge case', $body);
        self::assertStringContainsString('not escalate it', $body);

        $this->expectException(HttpException::class);

        $staffController->escalate(
            $this->post('/urgent-cases/' . $case->reference . '/escalate', [
                'level' => '1',
                'reason' => 'No answer after several attempts.',
            ]),
            ['reference' => $case->reference],
        );
    }

    public function testAnUnknownReferenceIsNotFound(): void
    {
        $this->expectException(HttpException::class);

        $this->controller->show(Request::create('GET', '/urgent-cases/UR-000000-XXXXXX'), [
            'reference' => 'UR-000000-XXXXXX',
        ]);
    }

    /**
     * Intake belongs to whoever may submit a referral into this service: §10.3 step 1
     * names "authorized referrer or staff", and both hold `referral.submit`.
     */
    public function testRegistrationThroughTheFormRecordsTheCaseAndSaysItIsNotAnAcknowledgement(): void
    {
        $clerk = $this->controllerFor('clerk@example.test');

        $response = $clerk->store($this->post('/urgent-cases', [
            'patient_name' => 'Bereket Tesfaye',
            'patient_dob' => '2025-09-02',
            'patient_age_months' => '14',
            'guardian_name' => 'Tesfaye Alemu',
            'guardian_phone' => '+251911222444',
            'guardian_relationship' => 'Father',
            'referring_physician_name' => 'Dr Hana',
            'referring_facility' => 'Adama Hospital',
            'referring_physician_phone' => '+251111551234',
            'receiving_hospital_name' => 'Black Lion Hospital',
            'clinical_summary' => 'Twelve hours of colicky pain with redcurrant stool.',
            'imaging_summary' => 'Target sign on transverse view.',
        ]));

        self::assertSame(303, $response->status);
        self::assertCount(1, $this->cases->openCases());
        self::assertSame(UrgentCoordination::Unacknowledged, $this->cases->openCases()[0]->coordination);
        self::assertStringContainsString(
            'This is not an acknowledgement',
            (string) Session::pullFlash('notice'),
        );
    }

    private function registerCase(string $patientName, string $receivedAtUtc): UrgentCase
    {
        return $this->register->execute(
            new Actor($this->staffId, Role::Staff),
            [
                'patient_name' => $patientName,
                'patient_dob' => '',
                'patient_age_months' => '14',
                'guardian_name' => 'Tesfaye Alemu',
                'guardian_phone' => '+251911222444',
                'guardian_relationship' => 'Father',
                'referring_physician_name' => 'Dr Hana',
                'referring_facility' => 'Adama Hospital',
                'clinical_summary' => 'Twelve hours of colicky pain with redcurrant stool.',
                'imaging_summary' => 'Target sign on transverse view.',
            ],
            $receivedAtUtc,
        );
    }

    /** @param array<string, string> $fields */
    private function post(string $path, array $fields): Request
    {
        return Request::create('POST', $path, [], $fields + [Csrf::FIELD => Csrf::token()]);
    }

    private function body(Response $response): string
    {
        return $response->body();
    }
}
