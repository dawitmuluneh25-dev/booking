<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\MyReferralsController;
use App\Domain\Actor;
use App\Domain\ClinicalDecision;
use App\Domain\ClinicalDecisionType;
use App\Domain\PatientRelationshipRepository;
use App\Domain\PhysicianProfile;
use App\Domain\PhysicianStatus;
use App\Domain\Referral;
use App\Domain\ReferralAccessPolicy;
use App\Domain\ReferralAccessResolver;
use App\Domain\ReferralStatus;
use App\Domain\Role;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Gate;
use App\Security\PasswordHasher;
use App\Support\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryPhysicianProfileRepository;
use Tests\Support\InMemoryReferralRepository;
use Tests\Support\InMemoryUserRepository;

/**
 * The referrer's own list, rendered through the real template.
 *
 * The point of the page is what it leaves out. A physician must see the referrals
 * their own verified profile is named on — including ones reception typed on their
 * behalf — and nothing of a colleague's, even at the same hospital (AUTH-03,
 * ADR-014). The profile, not the submitting account, is the boundary, so the test
 * seeds a referral submitted by a clerk under the physician's profile.
 */
final class MyReferralsPageTest extends TestCase
{
    private const NOW = '2026-11-13 09:00:00';

    private InMemoryUserRepository $users;

    private InMemoryPhysicianProfileRepository $profiles;

    private InMemoryReferralRepository $referrals;

    private int $mineProfileId;

    private int $clerkId;

    protected function setUp(): void
    {
        $_SESSION = [];

        $hash = (new PasswordHasher())->hash('correct horse');

        $this->users = new InMemoryUserRepository();
        $referrerId = $this->users->seed('referrer@example.test', 'Dr Hana Bekele', Role::Physician, $hash);
        $colleagueId = $this->users->seed('colleague@example.test', 'Dr Dawit Muluneh', Role::Physician, $hash);
        $unverifiedId = $this->users->seed('new@example.test', 'Dr New', Role::Physician, $hash);
        $this->users->seed('unlinked@example.test', 'Dr Unlinked', Role::Physician, $hash);
        $this->clerkId = $this->users->seed('clerk@example.test', 'Clerk', Role::Staff, $hash);

        $this->profiles = new InMemoryPhysicianProfileRepository();
        $this->mineProfileId = $this->profiles->create(
            $this->profile($referrerId, PhysicianStatus::Verified),
            self::NOW,
        )->id;
        $colleagueProfileId = $this->profiles->create(
            $this->profile($colleagueId, PhysicianStatus::Verified),
            self::NOW,
        )->id;
        $this->profiles->create($this->profile($unverifiedId, PhysicianStatus::Pending), self::NOW);

        $this->referrals = new InMemoryReferralRepository();
        $this->referrals->create(
            $this->referral('RF-261113-MINE01', $this->mineProfileId, $referrerId),
            new Actor($referrerId, Role::Physician),
            self::NOW,
        );
        $this->referrals->create(
            $this->referral('RF-261113-THEIR1', $colleagueProfileId, $colleagueId),
            new Actor($colleagueId, Role::Physician),
            self::NOW,
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAPhysicianSeesOnlyTheirOwnReferrals(): void
    {
        $body = $this->body('referrer@example.test');

        self::assertStringContainsString('RF-261113-MINE01', $body);
        self::assertStringNotContainsString('RF-261113-THEIR1', $body);
    }

    /**
     * The mirror image, so a page that happened to list everything and a page that
     * happened to list nothing cannot both pass.
     */
    public function testTheColleagueSeesTheirOwnReferralAndNotTheOther(): void
    {
        $body = $this->body('colleague@example.test');

        self::assertStringContainsString('RF-261113-THEIR1', $body);
        self::assertStringNotContainsString('RF-261113-MINE01', $body);
    }

    /**
     * A referral entered by reception under the physician's profile is still that
     * physician's referral: the profile is the boundary, not who typed the form.
     */
    public function testAReferralEnteredByStaffBelongsToTheNamedPhysician(): void
    {
        $clerkId = $this->users->seed(
            'second-clerk@example.test',
            'Second Clerk',
            Role::Staff,
            (new PasswordHasher())->hash('correct horse'),
        );

        $this->referrals->create(
            $this->referral('RF-261113-CLERK1', $this->mineProfileId, $clerkId),
            new Actor($clerkId, Role::Staff),
            self::NOW,
        );

        self::assertStringContainsString('RF-261113-CLERK1', $this->body('referrer@example.test'));
    }

    public function testAnUnverifiedPhysicianIsToldWhyNothingIsListed(): void
    {
        $body = $this->body('new@example.test');

        self::assertStringContainsString('awaiting verification', $body);
        self::assertStringNotContainsString('RF-261113-MINE01', $body);
        // The withheld message replaces the "you have not referred anyone" line:
        // there is a difference between an empty list and a list that may not be read.
        self::assertStringNotContainsString('You have not referred anyone yet', $body);
    }

    public function testAPhysicianWithoutAReferrerRecordIsToldSo(): void
    {
        $body = $this->body('unlinked@example.test');

        self::assertStringContainsString('not linked to a referrer record', $body);
    }

    public function testAVerifiedPhysicianWithNothingSentSeesTheEmptyState(): void
    {
        $body = $this->body('colleague@example.test', clearReferrals: true);

        self::assertStringContainsString('You have not referred anyone yet', $body);
    }

    /**
     * The reason a referral is back with the referrer is recorded on the transition
     * when reception asks for a missing document. The list has to carry it, or
     * "awaiting information" is a status with nothing to act on.
     */
    public function testWhatReceptionAskedForIsShown(): void
    {
        $referral = $this->referrals->create(
            $this->referral('RF-261113-INFO01', $this->mineProfileId, 1),
            new Actor(1, Role::Physician),
            self::NOW,
        );

        $clerk = new Actor($this->clerkId, Role::Staff);

        $this->moveTo($referral, ReferralStatus::Received, $clerk);
        $this->moveTo($referral, ReferralStatus::AdministrativeReview, $clerk);
        $this->moveTo(
            $referral,
            ReferralStatus::AwaitingInformation,
            $clerk,
            'Send the most recent ultrasound report.',
        );

        self::assertStringContainsString(
            'Send the most recent ultrasound report.',
            $this->body('referrer@example.test'),
        );
    }

    /**
     * A radiologist's request for information is a clinical decision, and it does
     * not put a reason on the transition (WF-03: asking is not one of the outcomes
     * that must justify itself). The list reads the decision instead of printing a
     * bare state with no question attached.
     */
    public function testWhatAClinicianAskedForIsShown(): void
    {
        $referral = $this->referrals->create(
            $this->referral('RF-261113-INFO02', $this->mineProfileId, 1),
            new Actor(1, Role::Physician),
            self::NOW,
        );

        $clerk = new Actor($this->clerkId, Role::Staff);
        $this->moveTo($referral, ReferralStatus::Received, $clerk);
        $this->moveTo($referral, ReferralStatus::AdministrativeReview, $clerk);
        $this->moveTo($referral, ReferralStatus::ClinicalReview, $clerk);

        $this->referrals->recordDecision(new ClinicalDecision(
            id: 0,
            referralId: $referral->id,
            type: ClinicalDecisionType::MoreInformation,
            rationale: 'The referral does not say whether the patient is still breastfeeding.',
            technique: null,
            modality: null,
            sedation: null,
            estimatedDurationMinutes: null,
            preprocedureConditions: null,
            redirectDestination: null,
            informationRequested: 'Confirm current breastfeeding status.',
            decidedByUserId: 3,
            decidedAtUtc: self::NOW,
            supersedesId: null,
            clinicalPriority: null,
        ));

        $this->moveTo(
            $referral,
            ReferralStatus::AwaitingInformation,
            new Actor(3, Role::Radiologist),
        );

        self::assertStringContainsString('Confirm current breastfeeding status.', $this->body('referrer@example.test'));
    }

    public function testAStaffMemberCannotOpenTheReferrerList(): void
    {
        $this->expectException(HttpException::class);

        $this->controller('clerk@example.test')->index(Request::create('GET', '/my-referrals'));
    }

    public function testASignedOutVisitorIsSentToSignIn(): void
    {
        $controller = new MyReferralsController(
            new View(dirname(__DIR__, 2) . '/templates'),
            new Auth($this->users, new PasswordHasher()),
            new Gate(new Auth($this->users, new PasswordHasher())),
            $this->referrals,
            new ReferralAccessResolver(
                new ReferralAccessPolicy(),
                $this->profiles,
                $this->noRelationships(),
            ),
            'Africa/Addis_Ababa',
        );

        $response = $controller->index(Request::create('GET', '/my-referrals'));

        self::assertSame(303, $response->status);
        self::assertSame('/login?next=%2Fmy-referrals', $response->header('Location'));
    }

    /**
     * Walk a referral one step, reloading first: the store refuses a write made
     * against a stale version, and a test is no reason to bypass the guarantee the
     * command tests rely on.
     */
    private function moveTo(
        Referral $referral,
        ReferralStatus $target,
        Actor $actor,
        ?string $reason = null,
    ): Referral {
        $current = $this->referrals->findById($referral->id);

        self::assertNotNull($current);

        return $this->referrals->transition($current, $target, $actor, $reason, self::NOW);
    }

    private function body(string $email, bool $clearReferrals = false): string
    {
        if ($clearReferrals) {
            $this->referrals = new InMemoryReferralRepository();
        }

        $response = $this->controller($email)->index(Request::create('GET', '/my-referrals'));

        self::assertInstanceOf(Response::class, $response);

        return $response->body();
    }

    private function controller(string $email): MyReferralsController
    {
        $auth = new Auth($this->users, new PasswordHasher());
        $auth->attempt($email, 'correct horse', '127.0.0.1');

        return new MyReferralsController(
            new View(dirname(__DIR__, 2) . '/templates'),
            $auth,
            new Gate($auth),
            $this->referrals,
            new ReferralAccessResolver(
                new ReferralAccessPolicy(),
                $this->profiles,
                $this->noRelationships(),
            ),
            'Africa/Addis_Ababa',
        );
    }

    private function profile(int $userId, PhysicianStatus $status): PhysicianProfile
    {
        return new PhysicianProfile(
            id: 0,
            userId: $userId,
            practiceNumber: 'ET-MD-' . $userId,
            institution: 'Yekatit 12 Hospital',
            specialty: 'Internal medicine',
            status: $status,
            verifiedByUserId: null,
            verifiedAtUtc: null,
            reviewNote: null,
            createdAtUtc: self::NOW,
        );
    }

    private function referral(string $reference, int $profileId, int $submittedByUserId): Referral
    {
        return new Referral(
            id: 0,
            reference: $reference,
            status: ReferralStatus::Submitted,
            version: 1,
            patientName: 'Selamawit Girma',
            patientPhone: '+251911000333',
            patientDob: '1991-04-12',
            guardianName: null,
            guardianPhone: null,
            guardianRelationship: null,
            procedureCode: 'image_guided_biopsy',
            requestedUrgency: 'routine',
            clinicalSummary: 'Six weeks of right upper quadrant pain.',
            targetSite: 'Liver, segment VI',
            referringPhysicianName: 'Dr Hana Bekele',
            referringFacility: 'Yekatit 12 Hospital',
            referringPhysicianPhone: null,
            submittedByUserId: $submittedByUserId,
            submittedOnBehalfOf: 'referrer',
            createdAtUtc: self::NOW,
            referringPhysicianProfileId: $profileId,
        );
    }

    private function noRelationships(): PatientRelationshipRepository
    {
        return new class implements PatientRelationshipRepository {
            public function patientsActedForBy(int $userId): array
            {
                return [];
            }

            public function actsFor(int $userId, int $patientUserId): bool
            {
                return false;
            }
        };
    }
}
