<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\ReferralController;
use App\Domain\Actor;
use App\Domain\ApproveForScheduling;
use App\Domain\ClinicalDecision;
use App\Domain\ClinicalDecisionType;
use App\Domain\Procedure;
use App\Domain\ReassignClinicalPriority;
use App\Domain\RecordProcedureConsent;
use App\Domain\WithdrawConsent;
use App\Domain\Referral;
use App\Domain\ReferralStatus;
use App\Domain\Role;
use App\Domain\SaveReferralDraft;
use App\Domain\SubmitDraft;
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
use Tests\Support\InMemoryConsentRepository;
use Tests\Support\InlineTransaction;
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

    private InMemoryConsentRepository $consents;

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

        $this->consents = new InMemoryConsentRepository();

        $this->controller = new ReferralController(
            new View(dirname(__DIR__, 2) . '/templates'),
            new Gate($auth),
            new SubmitElectiveReferral(
                $this->referrals,
                new Validator(),
                new InMemoryIdempotencyStore(),
                new InMemoryPhysicianProfileRepository(),
            ),
            new SaveReferralDraft($this->referrals, $procedures),
            new SubmitDraft($this->referrals),
            $this->referrals,
            $procedures,
            new ApproveForScheduling($this->referrals),
            new ReassignClinicalPriority($this->referrals, new Validator(), new InlineTransaction()),
            $this->consents,
            new RecordProcedureConsent(
                $this->consents,
                $this->referrals,
                new Validator(),
                new InlineTransaction(),
            ),
            new WithdrawConsent($this->consents, new Validator()),
            'Africa/Addis_Ababa',
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

    /**
     * The form offers the approved requestable categories and nothing else
     * (HR-IR-CLIN-R1-001 §13.1, A-08). "Urgent direct coordination" is a clinical
     * decision to leave the ordinary queue; it must not appear as an option, and
     * the retired word must not survive in the markup.
     */
    public function testTheFormOffersTheApprovedPriorityCategoriesOnly(): void
    {
        $body = $this->body($this->controller->create(Request::create('GET', '/referrals/new')));

        // The option text is indented by the template, so the assertion is on the
        // words rather than on the whitespace around them.
        self::assertStringContainsString('Routine', $body);
        self::assertStringContainsString('Expedited', $body);
        self::assertStringNotContainsString('Urgent direct coordination', $body);
        self::assertStringNotContainsString('value="priority"', $body);
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

    public function testADraftCanBeSavedAndIsNotInTheQueue(): void
    {
        $payload = $this->payload();
        $payload['patient_name'] = '';
        $payload['clinical_summary'] = '';

        $response = $this->controller->saveDraft(
            Request::create('POST', '/referrals/draft', [], $payload + [Csrf::FIELD => Csrf::token()]),
        );

        self::assertSame(303, $response->status);
        self::assertStringStartsWith('/referrals/', (string) $response->header('Location'));

        $queue = $this->body($this->controller->index(Request::create('GET', '/referrals')));
        self::assertStringNotContainsString('Draft (not submitted)', $queue);
    }

    /**
     * A draft used to store whatever urgency it was given, so an unknown value
     * could reach the column. Now that the column holds the approved vocabulary
     * (§13.1, A-08), an unvalidated draft value would surface as a database error
     * on a form a patient is half-way through. It is refused as a field error.
     */
    public function testADraftRefusesAPriorityTheApprovedModelDoesNotOffer(): void
    {
        $payload = $this->payload();
        $payload['requested_urgency'] = 'priority';

        $response = $this->controller->saveDraft(
            Request::create('POST', '/referrals/draft', [], $payload + [Csrf::FIELD => Csrf::token()]),
        );

        self::assertSame(422, $response->status);

        $body = $this->body($response);

        self::assertStringContainsString('routine or expedited', $body);
        self::assertStringNotContainsString('SQLSTATE', $body);
    }

    public function testAnIncompleteDraftCannotBeSubmitted(): void
    {
        $payload = $this->payload();
        $payload['clinical_summary'] = '';

        $saved = $this->controller->saveDraft(
            Request::create('POST', '/referrals/draft', [], $payload + [Csrf::FIELD => Csrf::token()]),
        );
        $location = (string) $saved->header('Location');
        $reference = basename($location);

        $response = $this->controller->submit(
            Request::create('POST', $location . '/submit', [], [Csrf::FIELD => Csrf::token()]),
            ['reference' => $reference],
        );

        // The draft is re-rendered with the missing piece marked, not redirected:
        // a flash message would vanish on the next page load.
        self::assertSame(422, $response->status);
        self::assertStringContainsString('not complete yet', $this->body($response));
        self::assertSame(ReferralStatus::Draft, $this->referrals->findByReference($reference)?->status);
    }

    public function testACompleteDraftBecomesASubmittedReferral(): void
    {
        $saved = $this->controller->saveDraft(
            Request::create('POST', '/referrals/draft', [], $this->payload() + [Csrf::FIELD => Csrf::token()]),
        );
        $location = (string) $saved->header('Location');
        $reference = basename($location);

        $this->controller->submit(
            Request::create('POST', $location . '/submit', [], [Csrf::FIELD => Csrf::token()]),
            ['reference' => $reference],
        );

        self::assertSame(ReferralStatus::Submitted, $this->referrals->findByReference($reference)?->status);
    }

    public function testTheDeskCanReceiveAReferralAndStartAdministrativeReview(): void
    {
        $this->advance('RF-2026-0001', ['action' => 'receive']);
        self::assertSame(ReferralStatus::Received, $this->statusOf('RF-2026-0001'));

        $this->advance('RF-2026-0001', ['action' => 'triage']);
        self::assertSame(ReferralStatus::AdministrativeReview, $this->statusOf('RF-2026-0001'));
    }

    public function testARequestForInformationMustSayWhatIsMissing(): void
    {
        $this->advance('RF-2026-0001', ['action' => 'receive']);
        $this->advance('RF-2026-0001', ['action' => 'triage']);

        $response = $this->advance('RF-2026-0001', ['action' => 'request-information'], 422);

        self::assertStringContainsString('Say what is missing', $this->body($response));
        self::assertSame(ReferralStatus::AdministrativeReview, $this->statusOf('RF-2026-0001'));
    }

    public function testARecordedReplyReturnsTheReferralToAdministrativeReviewWithHistory(): void
    {
        $this->advance('RF-2026-0001', ['action' => 'receive']);
        $this->advance('RF-2026-0001', ['action' => 'triage']);
        $this->advance('RF-2026-0001', ['action' => 'request-information', 'reason' => 'Need the CT report.']);

        self::assertSame(ReferralStatus::AwaitingInformation, $this->statusOf('RF-2026-0001'));

        $referral = $this->referrals->findByReference('RF-2026-0001');
        self::assertInstanceOf(Referral::class, $referral);

        $response = $this->controller->reply(
            Request::create('POST', '/referrals/RF-2026-0001/reply', [], [
                'reply' => 'CT report uploaded.',
                Csrf::FIELD => Csrf::token(),
            ]),
            ['reference' => 'RF-2026-0001'],
        );

        self::assertSame(303, $response->status);
        self::assertSame(ReferralStatus::AdministrativeReview, $this->statusOf('RF-2026-0001'));

        $history = $this->referrals->historyFor($referral->id);
        $reasons = array_map(static fn ($row): string => (string) ($row['reason'] ?? ''), $history);

        self::assertContains('Need the CT report.', $reasons);
        self::assertContains('CT report uploaded.', $reasons);
    }

    public function testTheDeskReleasesAClinicallyApprovedReferralToTheSchedulingQueue(): void
    {
        $referral = $this->approvedReferral();

        $response = $this->controller->release(
            Request::create('POST', '/referrals/' . $referral->reference . '/release', [], [
                Csrf::FIELD => Csrf::token(),
            ]),
            ['reference' => $referral->reference],
        );

        self::assertSame(303, $response->status);
        self::assertSame(ReferralStatus::ApprovedForScheduling, $this->statusOf($referral->reference));
    }

    public function testAReferralWhoseStandingDecisionIsNotAnApprovalCannotBeReleased(): void
    {
        $referral = $this->approvedReferral();
        $approved = $this->referrals->findByReference($referral->reference);
        self::assertInstanceOf(Referral::class, $approved);

        // A later decision replaced the approval: the status still reads approved,
        // and the page must refuse rather than book on a judgement that was undone.
        $current = $this->referrals->currentDecision($approved->id);
        self::assertNotNull($current);
        $this->referrals->recordDecision(new ClinicalDecision(
            id: 0,
            referralId: $approved->id,
            type: ClinicalDecisionType::Deferred,
            rationale: 'The lesion is better assessed after a repeat CT in six weeks.',
            technique: null,
            modality: null,
            sedation: null,
            estimatedDurationMinutes: null,
            preprocedureConditions: null,
            redirectDestination: null,
            informationRequested: null,
            decidedByUserId: 3,
            decidedAtUtc: '2026-11-10 10:00:00',
            supersedesId: $current->id,
        ));

        $response = $this->controller->release(
            Request::create('POST', '/referrals/' . $approved->reference . '/release', [], [
                Csrf::FIELD => Csrf::token(),
            ]),
            ['reference' => $approved->reference],
        );

        self::assertSame(422, $response->status);
        self::assertStringContainsString('standing clinical approval', $this->body($response));
        self::assertSame(ReferralStatus::ClinicallyApproved, $this->statusOf($approved->reference));
    }

    /** A referral that has been through triage and carries a standing approval. */
    private function approvedReferral(): Referral
    {
        $this->advance('RF-2026-0001', ['action' => 'receive']);
        $this->advance('RF-2026-0001', ['action' => 'triage']);
        $this->advance('RF-2026-0001', ['action' => 'send-to-review']);

        $referral = $this->referrals->findByReference('RF-2026-0001');
        self::assertInstanceOf(Referral::class, $referral);

        $this->referrals->recordDecision(new ClinicalDecision(
            id: 0,
            referralId: $referral->id,
            type: ClinicalDecisionType::Approved,
            rationale: 'Lesion is accessible and the indication is clear.',
            technique: null,
            modality: null,
            sedation: null,
            estimatedDurationMinutes: null,
            preprocedureConditions: null,
            redirectDestination: null,
            informationRequested: null,
            decidedByUserId: 3,
            decidedAtUtc: '2026-11-10 09:30:00',
            supersedesId: null,
        ));

        return $this->referrals->transition(
            $referral,
            ReferralStatus::ClinicallyApproved,
            new Actor(3, Role::Radiologist),
            null,
            '2026-11-10 09:30:00',
        );
    }

    private function statusOf(string $reference): ReferralStatus
    {
        $referral = $this->referrals->findByReference($reference);
        self::assertInstanceOf(Referral::class, $referral);

        return $referral->status;
    }

    /** @param array<string, string> $body */
    private function advance(string $reference, array $body, int $expected = 303): Response
    {
        $response = $this->controller->advance(
            Request::create('POST', '/referrals/' . $reference . '/advance', [], $body + [
                Csrf::FIELD => Csrf::token(),
            ]),
            ['reference' => $reference],
        );

        self::assertSame($expected, $response->status);

        return $response;
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

    /**
     * Consent is stated as absent, not implied, and reception is not offered a form
     * it may not submit (§18.1, §18.2).
     */
    public function testTheReferralPageSaysNoConsentIsRecordedAndOffersReceptionNoForm(): void
    {
        $body = $this->body($this->controller->show(
            Request::create('GET', '/referrals/RF-2026-0001'),
            ['reference' => 'RF-2026-0001'],
        ));

        self::assertStringContainsString('Consent', $body);
        self::assertStringContainsString('No consent discussion is recorded yet', $body);
        self::assertStringContainsString('the system never records consent on its own', mb_strtolower($body));

        // The clerk holds no clinical permission, so neither write is offered.
        self::assertStringNotContainsString('Record consent', $body);
        self::assertStringNotContainsString('Withdraw consent', $body);
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
