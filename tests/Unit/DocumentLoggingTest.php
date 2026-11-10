<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\DocumentController;
use App\Domain\Actor;
use App\Domain\DocumentAccessService;
use App\Domain\DocumentKind;
use App\Domain\DocumentRepository;
use App\Domain\DocumentScanStatus;
use App\Domain\DocumentTypes;
use App\Domain\PatientRelationshipRepository;
use App\Domain\Referral;
use App\Domain\ReferralAccessPolicy;
use App\Domain\ReferralAccessResolver;
use App\Domain\ReferralDocument;
use App\Domain\ReferralStatus;
use App\Domain\ReviewQuarantinedDocument;
use App\Domain\Role;
use App\Domain\StorageKey;
use App\Domain\UploadReferralDocument;
use App\Domain\DocumentWorkspace;
use App\Domain\User;
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
use Tests\Support\InlineTransaction;
use Tests\Support\RecordingAuditTrail;

/**
 * Phase 4 criterion 3.
 *
 * A query string is the easiest place for a patient's name to end up in a log
 * file, because it is written by the browser rather than by this code. The test
 * therefore sends a request that carries one, downloads a document whose filename
 * is also identifying, and then reads the log the application actually wrote.
 */
final class DocumentLoggingTest extends TestCase
{
    private const NOW = '2026-11-09 14:00:00';

    private string $logDirectory;

    private Logger $logger;

    protected function setUp(): void
    {
        $_SESSION = [];

        $this->logDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'doc-log-' . bin2hex(random_bytes(6));
        $this->logger = new Logger($this->logDirectory);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];

        if (is_dir($this->logDirectory)) {
            foreach ((array) glob($this->logDirectory . DIRECTORY_SEPARATOR . '*') as $file) {
                @unlink((string) $file);
            }

            @rmdir($this->logDirectory);
        }
    }

    public function testNeitherTheFilenameNorAQueryStringReachesTheLog(): void
    {
        [$controller, $document] = $this->controllerWithDocument();

        $request = Request::create(
            'GET',
            '/documents/' . $document->documentRef . '/download',
            ['patient' => 'Jane Doe', 'note' => 'suspected lymphoma'],
        );

        $response = $controller->download($request, ['ref' => $document->documentRef]);

        self::assertSame(200, $response->status);
        self::assertSame('%PDF-1.4 bytes', $response->body());

        $log = $this->logContents();

        self::assertStringContainsString('document.download', $log);
        self::assertStringContainsString($document->documentRef, $log);
        self::assertStringNotContainsString('Jane', $log);
        self::assertStringNotContainsString('lymphoma', $log);
        self::assertStringNotContainsString('scan.pdf', $log);
    }

    public function testTheDownloadIsReturnedAsAnAttachmentThatNoCacheMayKeep(): void
    {
        [$controller, $document] = $this->controllerWithDocument();

        $response = $controller->download(
            Request::create('GET', '/documents/' . $document->documentRef . '/download'),
            ['ref' => $document->documentRef],
        );

        self::assertSame('application/pdf', $response->header('Content-Type'));
        self::assertStringContainsString('attachment', (string) $response->header('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
    }

    /**
     * @return array{0: DocumentController, 1: ReferralDocument}
     */
    private function controllerWithDocument(): array
    {
        $users = new \Tests\Support\InMemoryUserRepository();
        $users->seed('clerk@example.test', 'Clerk', Role::Staff, (new PasswordHasher())->hash('correct horse'));
        $auth = new Auth($users, new PasswordHasher());
        $auth->attempt('clerk@example.test', 'correct horse', '127.0.0.1');

        $documents = new InMemoryDocumentRepository();
        $storage = new InMemoryDocumentStorage();
        $referrals = new InMemoryReferralRepository();

        $referral = $referrals->create($this->referralEntity(), new Actor(7, Role::Staff), self::NOW);

        $key = StorageKey::generate();
        $storage->seed($key, '%PDF-1.4 bytes');

        $document = $documents->create(
            new ReferralDocument(
                id: 0,
                documentRef: bin2hex(random_bytes(16)),
                referralId: $referral->id,
                kind: DocumentKind::ImagingReport,
                originalName: 'scan.pdf',
                mimeType: 'application/pdf',
                fileExtension: 'pdf',
                byteSize: 15,
                sha256: str_repeat('f', 64),
                storageKey: $key,
                scanStatus: DocumentScanStatus::Clean,
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
            new \App\Domain\ReferralTrackingLink(new InMemoryTrackingTokenRepository(), $referrals),
            new DocumentTypes(),
        );

        $controller = new DocumentController(
            new View(dirname(__DIR__, 2) . '/templates'),
            new Gate($auth),
            $this->logger,
            static fn (): DocumentWorkspace => $workspace,
            'UTC',
            'http://localhost:8000',
            15 * 1024 * 1024,
        );

        return [$controller, $document];
    }

    private function logContents(): string
    {
        $files = (array) glob($this->logDirectory . DIRECTORY_SEPARATOR . '*.log');
        $contents = '';

        foreach ($files as $file) {
            $contents .= (string) file_get_contents((string) $file);
        }

        return $contents;
    }

    private function referralEntity(): Referral
    {
        return new Referral(
            id: 0,
            reference: 'RF-2026-0031',
            status: ReferralStatus::AwaitingImaging,
            version: 1,
            patientName: 'Jane Doe',
            patientPhone: '+251911000031',
            patientDob: null,
            guardianName: null,
            guardianPhone: null,
            guardianRelationship: null,
            procedureCode: 'image_guided_biopsy',
            requestedUrgency: 'routine',
            clinicalSummary: null,
            targetSite: null,
            referringPhysicianName: null,
            referringFacility: null,
            referringPhysicianPhone: null,
            submittedByUserId: 7,
            submittedOnBehalfOf: 'staff',
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
