<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\DocumentController;
use App\Domain\Actor;
use App\Domain\DocumentAccessService;
use App\Domain\DocumentKind;
use App\Domain\DocumentScanStatus;
use App\Domain\DocumentTypes;
use App\Domain\DocumentWorkspace;
use App\Domain\PatientRelationshipRepository;
use App\Domain\Referral;
use App\Domain\ReferralAccessPolicy;
use App\Domain\ReferralAccessResolver;
use App\Domain\ReferralDocument;
use App\Domain\ReferralStatus;
use App\Domain\ReferralTrackingLink;
use App\Domain\ReviewQuarantinedDocument;
use App\Domain\Role;
use App\Domain\StorageKey;
use App\Domain\UploadReferralDocument;
use App\Http\HttpException;
use App\Http\Request;
use App\Security\Auth;
use App\Security\Gate;
use App\Security\PasswordHasher;
use App\Support\Logger;
use App\Support\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDocumentScanner;
use Tests\Support\InMemoryDocumentRepository;
use Tests\Support\InMemoryDocumentStorage;
use Tests\Support\InMemoryPhysicianProfileRepository;
use Tests\Support\InMemoryReferralRepository;
use Tests\Support\InMemoryTrackingTokenRepository;
use Tests\Support\InMemoryUserRepository;
use Tests\Support\InlineTransaction;
use Tests\Support\RecordingAuditTrail;

/**
 * The two pages, rendered through the real templates.
 *
 * A template error is invisible until somebody opens the page, and the panel is
 * the only place a clinician meets the quarantine rule. Rendering it here is
 * cheaper than discovering an undefined variable in production.
 */
final class DocumentPageTest extends TestCase
{
    private const NOW = '2026-11-09 15:00:00';

    private DocumentController $controller;

    private InMemoryReferralRepository $referrals;

    private ReferralTrackingLink $links;

    protected function setUp(): void
    {
        $_SESSION = [];

        $users = new InMemoryUserRepository();
        $users->seed('clerk@example.test', 'Clerk', Role::Staff, (new PasswordHasher())->hash('correct horse'));
        $auth = new Auth($users, new PasswordHasher());
        $auth->attempt('clerk@example.test', 'correct horse', '127.0.0.1');

        $documents = new InMemoryDocumentRepository();
        $storage = new InMemoryDocumentStorage();
        $referrals = new InMemoryReferralRepository();

        $referral = $referrals->create($this->referralEntity(), new Actor(7, Role::Staff), self::NOW);

        $this->referrals = $referrals;

        $documents->create(
            new ReferralDocument(
                id: 0,
                documentRef: str_repeat('a', 32),
                referralId: $referral->id,
                kind: DocumentKind::ImagingReport,
                originalName: 'scan.pdf',
                mimeType: 'application/pdf',
                fileExtension: 'pdf',
                byteSize: 2048,
                sha256: str_repeat('f', 64),
                storageKey: StorageKey::generate(),
                scanStatus: DocumentScanStatus::Pending,
                accessClass: DocumentKind::ImagingReport->accessClass(),
                uploadedByUserId: 7,
                createdAtUtc: self::NOW,
            ),
            7,
            self::NOW,
        );

        $resolver = new ReferralAccessResolver(
            new ReferralAccessPolicy(),
            new InMemoryPhysicianProfileRepository(),
            $this->noRelationships(),
        );

        $access = new DocumentAccessService($documents, $referrals, $resolver);
        $this->links = new ReferralTrackingLink(new InMemoryTrackingTokenRepository(), $referrals);

        $workspace = new DocumentWorkspace(
            $referrals,
            $documents,
            $storage,
            $resolver,
            new UploadReferralDocument(
                $documents,
                $storage,
                FakeDocumentScanner::detecting('application/pdf'),
                $resolver,
                new InlineTransaction(),
            ),
            new ReviewQuarantinedDocument($documents, $access, new RecordingAuditTrail(), new InlineTransaction()),
            $access,
            $this->links,
            new DocumentTypes(),
        );

        $this->controller = new DocumentController(
            new View(dirname(__DIR__, 2) . '/templates'),
            new Gate($auth),
            new Logger(sys_get_temp_dir() . '/doc-pages'),
            static fn (): DocumentWorkspace => $workspace,
            'UTC',
            'http://localhost:8000',
            15 * 1024 * 1024,
            static fn (): string => self::NOW,
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testThePanelListsTheFileAndSaysItIsAwaitingReview(): void
    {
        $response = $this->controller->index(
            Request::create('GET', '/referrals/RF-2026-0041/documents'),
            ['reference' => 'RF-2026-0041'],
        );

        $body = $response->body();

        self::assertSame(200, $response->status);
        self::assertStringContainsString('RF-2026-0041', $body);
        self::assertStringContainsString('scan.pdf', $body);
        self::assertStringContainsString('Awaiting review', $body);
        // Quarantined bytes have no download link, only a state.
        self::assertStringNotContainsString('/download', $body);
    }

    public function testTheStatusPageShowsTheReferenceAndWithholdsIdentity(): void
    {
        $referral = $this->referrals->findByReference('RF-2026-0041');
        self::assertNotNull($referral);

        $token = $this->links->issue(
            new \App\Domain\User(7, 'clerk@example.test', 'Clerk', Role::Staff, true),
            $referral,
            self::NOW,
        );

        $response = $this->controller->showStatus(Request::create('GET', '/r/' . $token), ['token' => $token]);
        $body = $response->body();

        self::assertSame(200, $response->status);
        self::assertStringContainsString('RF-2026-0041', $body);
        self::assertStringContainsString('Awaiting imaging', $body);
        self::assertStringNotContainsString('Jane Doe', $body);
        self::assertStringNotContainsString('+251911000041', $body);
        self::assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
    }

    public function testAUsedStatusLinkIsNotFound(): void
    {
        $referral = $this->referrals->findByReference('RF-2026-0041');
        self::assertNotNull($referral);

        $token = $this->links->issue(
            new \App\Domain\User(7, 'clerk@example.test', 'Clerk', Role::Staff, true),
            $referral,
            self::NOW,
        );

        $this->controller->showStatus(Request::create('GET', '/r/' . $token), ['token' => $token]);

        $this->expectException(HttpException::class);

        $this->controller->showStatus(Request::create('GET', '/r/' . $token), ['token' => $token]);
    }

    private function referralEntity(): Referral
    {
        return new Referral(
            id: 0,
            reference: 'RF-2026-0041',
            status: ReferralStatus::AwaitingImaging,
            version: 1,
            patientName: 'Jane Doe',
            patientPhone: '+251911000041',
            patientDob: '1970-02-03',
            guardianName: null,
            guardianPhone: null,
            guardianRelationship: null,
            procedureCode: 'image_guided_biopsy',
            requestedUrgency: 'routine',
            clinicalSummary: 'Suspected lymphoma',
            targetSite: 'Lung',
            referringPhysicianName: 'Dr Referrer',
            referringFacility: 'Clinic',
            referringPhysicianPhone: null,
            submittedByUserId: 3,
            submittedOnBehalfOf: 'self',
            createdAtUtc: self::NOW,
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
