<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\ReferralController;
use App\Domain\Actor;
use App\Domain\Procedure;
use App\Domain\Referral;
use App\Domain\ReferralStatus;
use App\Domain\Role;
use App\Domain\SubmitElectiveReferral;
use App\Validation\Validator;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\PasswordHasher;
use App\Support\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryIdempotencyStore;
use Tests\Support\InMemoryPhysicianProfileRepository;
use Tests\Support\InMemoryProcedureRepository;
use Tests\Support\InMemoryReferralRepository;
use Tests\Support\InMemoryUserRepository;

/**
 * The referral queue and intake form, rendered through the real templates.
 *
 * A template error is invisible until somebody opens the page, and this is the
 * page a referrer meets first.
 */
final class ReferralPageTest extends TestCase
{
    private const NOW = '2026-11-10 09:00:00';

    private ReferralController $controller;

    private InMemoryReferralRepository $referrals;

    protected function setUp(): void
    {
        $_SESSION = [];

        $users = new InMemoryUserRepository();
        $users->seed('clerk@example.test', 'Clerk', Role::Staff, (new PasswordHasher())->hash('correct horse'));

        $auth = new Auth($users, new PasswordHasher());
        $auth->attempt('clerk@example.test', 'correct horse', '127.0.0.1');

        $procedures = new InMemoryProcedureRepository();
        $procedures->add($this->procedure('image_guided_fnac', 'Image-guided fine needle aspiration (FNAC)'));
        $procedures->add($this->procedure('image_guided_abscess_drainage', 'Image-guided abscess drainage'));

        $this->referrals = new InMemoryReferralRepository();
        $this->referrals->create($this->referralEntity(), new Actor(7, Role::Staff), self::NOW);

        $this->controller = new ReferralController(
            new View(dirname(__DIR__, 2) . '/templates'),
            new Gate($auth),
            new SubmitElectiveReferral(
                $this->referrals,
                new Validator(),
                new InMemoryIdempotencyStore(),
                new InMemoryPhysicianProfileRepository(),
            ),
            $this->referrals,
            $procedures,
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheQueueListsWhatIsWaiting(): void
    {
        $response = $this->controller->index(Request::create('GET', '/referrals'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('RF-2026-0001', $this->body($response));
    }

    public function testTheFormOffersOnlyTheConfiguredCatalogueAndSaysItIsNotAnAppointment(): void
    {
        $body = $this->body($this->controller->create(Request::create('GET', '/referrals/new')));

        self::assertStringContainsString('Image-guided fine needle aspiration (FNAC)', $body);
        self::assertStringContainsString('Image-guided abscess drainage', $body);
        self::assertStringContainsString('not an appointment', $body);
        // The code is an identifier; a referrer chooses a name.
        self::assertStringNotContainsString('>image_guided_fnac<', $body);
    }

    public function testTheDetailPageNamesTheProcedureRatherThanPrintingItsCode(): void
    {
        $response = $this->controller->show(
            Request::create('GET', '/referrals/RF-2026-0001'),
            ['reference' => 'RF-2026-0001'],
        );

        $body = $this->body($response);

        self::assertStringContainsString('RF-2026-0001', $body);
        self::assertStringContainsString('Image-guided fine needle aspiration (FNAC)', $body);
        self::assertStringContainsString('no time has been', $body);
    }

    public function testAnUnknownReferenceIsNotFound(): void
    {
        $this->expectException(HttpException::class);

        $this->controller->show(Request::create('GET', '/referrals/RF-0000-0000'), ['reference' => 'RF-0000-0000']);
    }

    public function testASubmissionWithoutACsrfTokenIsRefusedBeforeItIsStored(): void
    {
        $this->expectException(HttpException::class);

        $this->controller->store(Request::create('POST', '/referrals', [], $this->payload()));
    }

    public function testASubmissionWithAValidTokenStoresTheReferralAndRedirectsToIt(): void
    {
        $response = $this->controller->store(
            Request::create('POST', '/referrals', [], $this->payload() + [Csrf::FIELD => Csrf::token()]),
        );

        self::assertSame(303, $response->status);
        self::assertStringStartsWith('/referrals/', (string) $response->header('Location'));
    }

    public function testAnInvalidSubmissionReRendersTheFormWithTheFieldMarked(): void
    {
        $payload = $this->payload();
        $payload['clinical_summary'] = 'short';

        $response = $this->controller->store(
            Request::create('POST', '/referrals', [], $payload + [Csrf::FIELD => Csrf::token()]),
        );

        self::assertSame(422, $response->status);

        $body = $this->body($response);

        self::assertStringContainsString('This referral was not submitted.', $body);
        self::assertStringContainsString('clinical_summary', $body);
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return [
            'patient_name' => 'Abebe Bekele',
            'patient_phone' => '+251911000001',
            'patient_dob' => '1980-04-02',
            'guardian_name' => '',
            'guardian_phone' => '',
            'guardian_relationship' => '',
            'procedure_code' => 'image_guided_fnac',
            'requested_urgency' => 'routine',
            'clinical_summary' => 'Palpable left breast mass, ultrasound shows a 14 mm lesion.',
            'target_site' => 'Left breast',
            'referring_physician_name' => 'Dr Hana Tesfaye',
            'referring_facility' => 'Black Lion Hospital',
            'referring_physician_phone' => '+251911000002',
        ];
    }

    private function body(Response $response): string
    {
        return $response->body();
    }

    private function procedure(string $code, string $label): Procedure
    {
        return new Procedure(
            code: $code,
            label: $label,
            category: 'elective',
            defaultDurationMinutes: 30,
            requiredFields: null,
            fieldSetVersion: 1,
            approvedBy: null,
            approvedAtUtc: '',
            active: true,
        );
    }

    private function referralEntity(): Referral
    {
        return new Referral(
            id: 0,
            reference: 'RF-2026-0001',
            status: ReferralStatus::Submitted,
            version: 1,
            patientName: 'Abebe Bekele',
            patientPhone: '+251911000001',
            patientDob: '1980-04-02',
            guardianName: null,
            guardianPhone: null,
            guardianRelationship: null,
            procedureCode: 'image_guided_fnac',
            requestedUrgency: 'routine',
            clinicalSummary: 'Palpable left breast mass.',
            targetSite: 'Left breast',
            referringPhysicianName: 'Dr Hana Tesfaye',
            referringFacility: 'Black Lion Hospital',
            referringPhysicianPhone: '+251911000002',
            submittedByUserId: 7,
            submittedOnBehalfOf: 'referrer',
            createdAtUtc: self::NOW,
            referringPhysicianProfileId: null,
        );
    }
}
