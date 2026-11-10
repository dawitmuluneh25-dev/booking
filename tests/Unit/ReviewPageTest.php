<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\ReviewController;
use App\Domain\Actor;
use App\Domain\ClinicalDecisionType;
use App\Domain\Procedure;
use App\Domain\RecordClinicalDecision;
use App\Domain\Referral;
use App\Domain\ReferralStatus;
use App\Domain\Role;
use App\Validation\Validator;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\PasswordHasher;
use App\Support\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryProcedureRepository;
use Tests\Support\InMemoryReferralRepository;
use Tests\Support\InlineTransaction;
use Tests\Support\InMemoryUserRepository;

/**
 * The clinical review surface.
 *
 * Two properties matter more than the markup: a person without `clinical.approve`
 * cannot record a decision through it, and the queue only ever shows referrals
 * that are actually awaiting review.
 */
final class ReviewPageTest extends TestCase
{
    private const NOW = '2026-11-10 09:00:00';

    private ReviewController $controller;

    private InMemoryReferralRepository $referrals;

    private int $radiologistId = 0;

    protected function setUp(): void
    {
        $_SESSION = [];

        $users = new InMemoryUserRepository();
        $hash = (new PasswordHasher())->hash('correct horse');

        $this->radiologistId = $users->seed('radiologist@example.test', 'Radiologist', Role::Radiologist, $hash);
        $users->seed('clerk@example.test', 'Clerk', Role::Staff, $hash);

        $auth = new Auth($users, new PasswordHasher());
        $auth->attempt('radiologist@example.test', 'correct horse', '127.0.0.1');

        $procedures = new InMemoryProcedureRepository();
        $procedures->add($this->procedure('image_guided_fnac', 'Image-guided fine needle aspiration (FNAC)'));

        $this->referrals = new InMemoryReferralRepository();
        $this->referrals->create($this->referralEntity(), new Actor($this->radiologistId, Role::Radiologist), self::NOW);

        $this->controller = new ReviewController(
            new View(dirname(__DIR__, 2) . '/templates'),
            new Gate($auth),
            new RecordClinicalDecision($this->referrals, new Validator(), new InlineTransaction()),
            $this->referrals,
            $procedures,
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheQueueShowsWhatIsAwaitingReviewWithTheProcedureNamed(): void
    {
        $body = $this->body($this->controller->index(Request::create('GET', '/review')));

        self::assertStringContainsString('RF-REV-0001', $body);
        self::assertStringContainsString('Image-guided fine needle aspiration (FNAC)', $body);
    }

    public function testTheDecisionFormRequiresARationale(): void
    {
        $response = $this->controller->decide(
            Request::create('POST', '/referrals/RF-REV-0001/decision', [], [
                'decision_type' => ClinicalDecisionType::Approved->value,
                'rationale' => 'short',
                Csrf::FIELD => Csrf::token(),
            ]),
            ['reference' => 'RF-REV-0001'],
        );

        self::assertSame(422, $response->status);
        self::assertStringContainsString('The decision was not recorded.', $this->body($response));
        self::assertSame(ReferralStatus::ClinicalReview, $this->statusOf('RF-REV-0001'));
    }

    public function testAnApprovalMovesTheReferralOutOfReview(): void
    {
        $response = $this->controller->decide(
            Request::create('POST', '/referrals/RF-REV-0001/decision', [], [
                'decision_type' => ClinicalDecisionType::Approved->value,
                'rationale' => 'Lesion is accessible and the indication is clear.',
                Csrf::FIELD => Csrf::token(),
            ]),
            ['reference' => 'RF-REV-0001'],
        );

        self::assertSame(303, $response->status);
        self::assertSame(ClinicalDecisionType::Approved->resultingStatus(), $this->statusOf('RF-REV-0001'));
    }

    public function testADecidedReferralCannotBeOpenedAgainForReview(): void
    {
        $this->controller->decide(
            Request::create('POST', '/referrals/RF-REV-0001/decision', [], [
                'decision_type' => ClinicalDecisionType::Approved->value,
                'rationale' => 'Lesion is accessible and the indication is clear.',
                Csrf::FIELD => Csrf::token(),
            ]),
            ['reference' => 'RF-REV-0001'],
        );

        $this->expectException(\App\Http\HttpException::class);

        $this->controller->show(Request::create('GET', '/review/RF-REV-0001'), ['reference' => 'RF-REV-0001']);
    }

    private function statusOf(string $reference): ReferralStatus
    {
        $referral = $this->referrals->findByReference($reference);
        self::assertInstanceOf(Referral::class, $referral);

        return $referral->status;
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
            reference: 'RF-REV-0001',
            status: ReferralStatus::ClinicalReview,
            version: 3,
            patientName: 'Abebe Bekele',
            patientPhone: '+251911000001',
            patientDob: '1980-04-02',
            guardianName: null,
            guardianPhone: null,
            guardianRelationship: null,
            procedureCode: 'image_guided_fnac',
            requestedUrgency: 'routine',
            clinicalSummary: 'Palpable left breast mass, 14 mm on ultrasound.',
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
