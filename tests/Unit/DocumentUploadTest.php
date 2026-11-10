<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\AuthorisationException;
use App\Domain\DocumentKind;
use App\Domain\DocumentScanStatus;
use App\Domain\PatientRelationshipRepository;
use App\Domain\Referral;
use App\Domain\ReferralAccessPolicy;
use App\Domain\ReferralAccessResolver;
use App\Domain\ReferralStatus;
use App\Domain\Role;
use App\Domain\UploadedFile;
use App\Domain\UploadReferralDocument;
use App\Domain\User;
use App\Domain\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDocumentScanner;
use Tests\Support\InMemoryDocumentRepository;
use Tests\Support\InMemoryDocumentStorage;
use Tests\Support\InMemoryPhysicianProfileRepository;
use Tests\Support\InlineTransaction;

/**
 * DOC-001 to DOC-004 and the upload half of SEC 14.1.
 *
 * The fixtures are real temp files because the command hashes and stores bytes;
 * faking that would test the fake. What the scanner says about those bytes is
 * injected, so each branch is exercised deliberately rather than depending on
 * what libmagic makes of a ten-byte file.
 */
final class DocumentUploadTest extends TestCase
{
    private const NOW = '2026-11-09 10:00:00';

    /** @var list<string> */
    private array $temporaryFiles = [];

    private InMemoryDocumentRepository $documents;

    private InMemoryDocumentStorage $storage;

    private UploadReferralDocument $command;

    protected function setUp(): void
    {
        $this->documents = new InMemoryDocumentRepository();
        $this->storage = new InMemoryDocumentStorage();

        $relationships = new class implements PatientRelationshipRepository {
            public function patientsActedForBy(int $userId): array
            {
                return [];
            }

            public function actsFor(int $userId, int $patientUserId): bool
            {
                return false;
            }
        };

        $this->command = new UploadReferralDocument(
            $this->documents,
            $this->storage,
            FakeDocumentScanner::detecting('application/pdf'),
            new ReferralAccessResolver(
                new ReferralAccessPolicy(),
                new InMemoryPhysicianProfileRepository(),
                $relationships,
            ),
            new InlineTransaction(),
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        $this->temporaryFiles = [];
    }

    public function testAnAcceptedUploadIsStoredUnderARandomKeyAndBornPending(): void
    {
        $document = $this->command->execute(
            $this->staff(),
            $this->referral(),
            DocumentKind::ImagingReport,
            $this->upload('scan.pdf', 'application/pdf', "%PDF-1.4\nscan"),
            self::NOW,
            '127.0.0.1',
            'PHPUnit',
        );

        self::assertSame(DocumentScanStatus::Pending, $document->scanStatus);
        self::assertSame('application/pdf', $document->mimeType);
        self::assertSame(1, $this->storage->count());
        self::assertStringNotContainsString('scan.pdf', $document->storageKey->value);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $document->documentRef);
        self::assertSame(hash('sha256', "%PDF-1.4\nscan"), $document->sha256);
    }

    public function testTheUploadIsAuditedWithTheActorAndNoFilename(): void
    {
        $document = $this->command->execute(
            $this->staff(),
            $this->referral(),
            DocumentKind::ReferralLetter,
            $this->upload('letter.pdf', 'application/pdf', '%PDF-1.4 letter'),
            self::NOW,
        );

        $events = $this->documents->recentAccessFor($document->id, 10);

        self::assertCount(1, $events);
        self::assertSame('upload', $events[0]['action']);
        self::assertSame('allowed', $events[0]['outcome']);
        self::assertSame(self::NOW, $events[0]['occurred_at']);
    }

    public function testADisallowedExtensionIsRefusedBeforeAnythingIsStored(): void
    {
        try {
            $this->command->execute(
                $this->staff(),
                $this->referral(),
                DocumentKind::Other,
                $this->upload('payload.php', 'text/x-php', '<?php echo 1;'),
                self::NOW,
            );
            self::fail('A PHP file was accepted as a clinical document.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('file', $exception->errors);
            self::assertSame(0, $this->storage->count());
        }
    }

    public function testContentThatDoesNotMatchTheExtensionIsRefused(): void
    {
        $this->command = $this->commandWithScanner(FakeDocumentScanner::detecting('image/png'));

        try {
            $this->command->execute(
                $this->staff(),
                $this->referral(),
                DocumentKind::ImagingReport,
                $this->upload('scan.pdf', 'application/pdf', "\x89PNG\r\n\x1a\nnot a pdf"),
                self::NOW,
            );
            self::fail('A PNG was stored as a PDF.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('does not match', $exception->errors['file'] ?? '');
            self::assertSame(0, $this->storage->count());
        }
    }

    public function testAnUnrecognisedFileIsRefused(): void
    {
        $this->command = $this->commandWithScanner(FakeDocumentScanner::rejecting());

        $this->expectException(ValidationException::class);

        $this->command->execute(
            $this->staff(),
            $this->referral(),
            DocumentKind::Other,
            $this->upload('scan.pdf', 'application/pdf', 'MZ not really a pdf'),
            self::NOW,
        );
    }

    public function testAFileOverTheConfiguredLimitIsRefused(): void
    {
        $this->command = new UploadReferralDocument(
            $this->documents,
            $this->storage,
            FakeDocumentScanner::detecting('application/pdf'),
            new ReferralAccessResolver(
                new ReferralAccessPolicy(),
                new InMemoryPhysicianProfileRepository(),
                $this->noRelationships(),
            ),
            new InlineTransaction(),
            maxBytes: 1024,
        );

        try {
            $this->command->execute(
                $this->staff(),
                $this->referral(),
                DocumentKind::Other,
                $this->upload('big.pdf', 'application/pdf', str_repeat('%PDF-', 500)),
                self::NOW,
            );
            self::fail('A file above the cap was stored.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('1 KiB', $exception->errors['file'] ?? '');
            self::assertSame(0, $this->storage->count());
        }
    }

    public function testAPathTraversalFilenameBecomesMetadataNotAPath(): void
    {
        $document = $this->command->execute(
            $this->staff(),
            $this->referral(),
            DocumentKind::Other,
            $this->upload('../../../../etc/passwd.pdf', 'application/pdf', '%PDF-1.4'),
            self::NOW,
        );

        self::assertStringNotContainsString('..', $document->storageKey->value);
        self::assertStringNotContainsString('/', $document->storageKey->value);
        self::assertSame(1, $this->storage->count());
    }

    public function testAnActorWhoCannotSeeTheReferralIsRefused(): void
    {
        $otherPhysician = new User(99, 'other@example.test', 'Other', Role::Physician, true);

        try {
            $this->command->execute(
                $otherPhysician,
                $this->referral(),
                DocumentKind::ImagingReport,
                $this->upload('scan.pdf', 'application/pdf', '%PDF-1.4'),
                self::NOW,
            );
            self::fail('A physician attached a document to somebody else\'s referral.');
        } catch (AuthorisationException) {
            self::assertSame(0, $this->storage->count());
        }
    }

    public function testAClosedReferralAcceptsNoNewDocuments(): void
    {
        $referral = $this->referral(ReferralStatus::Closed, 42);

        try {
            $this->command->execute(
                $this->staff(),
                $referral,
                DocumentKind::Other,
                $this->upload('scan.pdf', 'application/pdf', '%PDF-1.4'),
                self::NOW,
            );
            self::fail('A terminal referral accepted a new document.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('referral', $exception->errors);
        }
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $this->command->execute(
            $this->staff(),
            $this->referral(),
            DocumentKind::Other,
            $this->upload('empty.pdf', 'application/pdf', ''),
            self::NOW,
        );
    }

    private function commandWithScanner(FakeDocumentScanner $scanner): UploadReferralDocument
    {
        return new UploadReferralDocument(
            $this->documents,
            $this->storage,
            $scanner,
            new ReferralAccessResolver(
                new ReferralAccessPolicy(),
                new InMemoryPhysicianProfileRepository(),
                $this->noRelationships(),
            ),
            new InlineTransaction(),
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

    private function staff(): User
    {
        return new User(7, 'clerk@example.test', 'Clerk', Role::Staff, true);
    }

    private function referral(ReferralStatus $status = ReferralStatus::Received, int $id = 5): Referral
    {
        return new Referral(
            id: $id,
            reference: 'RF-2026-0001',
            status: $status,
            version: 1,
            patientName: 'Patient One',
            patientPhone: '+251911000001',
            patientDob: '1980-01-01',
            guardianName: null,
            guardianPhone: null,
            guardianRelationship: null,
            procedureCode: 'image_guided_biopsy',
            requestedUrgency: 'routine',
            clinicalSummary: 'A summary',
            targetSite: 'Liver',
            referringPhysicianName: 'Dr Referrer',
            referringFacility: 'Clinic',
            referringPhysicianPhone: '+251911000002',
            submittedByUserId: 3,
            submittedOnBehalfOf: 'self',
            createdAtUtc: self::NOW,
        );
    }

    private function upload(string $name, string $type, string $contents): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($name, $type, $path, strlen($contents), UPLOAD_ERR_OK);
    }
}
