<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\DocumentAccessService;
use App\Domain\DocumentAction;
use App\Domain\DocumentKind;
use App\Domain\DocumentNotFoundException;
use App\Domain\DocumentQuarantinedException;
use App\Domain\DocumentReviewException;
use App\Domain\DocumentScanStatus;
use App\Domain\PatientRelationshipRepository;
use App\Domain\Referral;
use App\Domain\ReferralAccessPolicy;
use App\Domain\ReferralAccessResolver;
use App\Domain\ReferralDocument;
use App\Domain\ReferralStatus;
use App\Domain\ReviewQuarantinedDocument;
use App\Domain\Role;
use App\Domain\StorageKey;
use App\Domain\User;
use App\Domain\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDocumentRepository;
use Tests\Support\InMemoryPhysicianProfileRepository;
use Tests\Support\InMemoryReferralRepository;
use Tests\Support\InlineTransaction;
use Tests\Support\RecordingAuditTrail;

/**
 * HAK-0904 and DOC-005: nothing is served until a named reviewer releases it.
 *
 * The compensating control for a host with no malware scanner is a person
 * recording a verdict, so the verdict itself has to be guarded: one decision per
 * document, a reason when the file is withheld, and no way for an upload to
 * release itself.
 */
final class DocumentQuarantineTest extends TestCase
{
    private const NOW = '2026-11-09 12:00:00';

    private InMemoryDocumentRepository $documents;

    private ReviewQuarantinedDocument $review;

    private DocumentAccessService $access;

    private Referral $referral;

    protected function setUp(): void
    {
        $this->documents = new InMemoryDocumentRepository();

        $referrals = new InMemoryReferralRepository();
        $this->referral = $referrals->create(
            new Referral(
                id: 0,
                reference: 'RF-2026-0011',
                status: ReferralStatus::ClinicalReview,
                version: 1,
                patientName: 'Patient Two',
                patientPhone: '+251911000011',
                patientDob: null,
                guardianName: null,
                guardianPhone: null,
                guardianRelationship: null,
                procedureCode: 'image_guided_biopsy',
                requestedUrgency: 'routine',
                clinicalSummary: 'Summary',
                targetSite: 'Lung',
                referringPhysicianName: null,
                referringFacility: null,
                referringPhysicianPhone: null,
                submittedByUserId: 3,
                submittedOnBehalfOf: 'self',
                createdAtUtc: self::NOW,
            ),
            new Actor(7, Role::Staff),
            self::NOW,
        );

        $resolver = new ReferralAccessResolver(
            new ReferralAccessPolicy(),
            new InMemoryPhysicianProfileRepository(),
            $this->noRelationships(),
        );

        $this->access = new DocumentAccessService($this->documents, $referrals, $resolver);
        $this->review = new ReviewQuarantinedDocument($this->documents, $this->access, new RecordingAuditTrail(), new InlineTransaction());
    }

    public function testReleasingADocumentMakesItServable(): void
    {
        $document = $this->pendingDocument();

        $released = $this->review->execute(
            $this->admin(),
            $document->documentRef,
            DocumentScanStatus::Clean,
            'Opened it; a normal report.',
            self::NOW,
        );

        self::assertSame(DocumentScanStatus::Clean, $released->scanStatus);
        self::assertSame(1, $released->verifiedByUserId);

        $served = $this->access->open($this->staff(), $document->documentRef, DocumentAction::Download, self::NOW);
        self::assertSame($document->id, $served->id);
    }

    public function testTheVerdictIsAuditedAsAReviewRatherThanADownload(): void
    {
        $document = $this->pendingDocument();

        $this->review->execute($this->admin(), $document->documentRef, DocumentScanStatus::Clean, '', self::NOW);

        $events = $this->documents->recentAccessFor($document->id, 10);

        self::assertSame('quarantine_review', $events[0]['action']);
        self::assertSame('allowed', $events[0]['outcome']);
    }

    public function testWithholdingADocumentRequiresAReason(): void
    {
        $document = $this->pendingDocument();

        try {
            $this->review->execute(
                $this->admin(),
                $document->documentRef,
                DocumentScanStatus::Infected,
                ' ',
                self::NOW,
            );
            self::fail('A document was withheld with no recorded reason.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('note', $exception->errors);
            $stored = $this->documents->findByRef($document->documentRef);
            self::assertSame(DocumentScanStatus::Pending, $stored?->scanStatus);
        }
    }

    public function testAnInfectedVerdictKeepsTheFileShut(): void
    {
        $document = $this->pendingDocument();

        $this->review->execute(
            $this->admin(),
            $document->documentRef,
            DocumentScanStatus::Infected,
            'Embedded executable.',
            self::NOW,
        );

        $this->expectException(DocumentQuarantinedException::class);

        $this->access->open($this->staff(), $document->documentRef, DocumentAction::Download, self::NOW);
    }

    public function testASecondReviewerCannotOverwriteTheFirstDecision(): void
    {
        $document = $this->pendingDocument();

        $this->review->execute($this->admin(), $document->documentRef, DocumentScanStatus::Clean, '', self::NOW);

        $this->expectException(DocumentReviewException::class);

        $this->review->execute(
            $this->admin(),
            $document->documentRef,
            DocumentScanStatus::Infected,
            'Second look.',
            self::NOW,
        );
    }

    public function testAReviewerWithoutTheQuarantinePermissionIsRefused(): void
    {
        $document = $this->pendingDocument();

        $this->expectException(AuthorisationException::class);

        $this->review->execute($this->staff(), $document->documentRef, DocumentScanStatus::Clean, '', self::NOW);
    }

    public function testPendingIsNotAnAcceptableVerdict(): void
    {
        $document = $this->pendingDocument();

        $this->expectException(ValidationException::class);

        $this->review->execute($this->admin(), $document->documentRef, DocumentScanStatus::Pending, '', self::NOW);
    }

    public function testReviewingAnUnknownDocumentIsNotFound(): void
    {
        $this->expectException(DocumentNotFoundException::class);

        $this->review->execute($this->admin(), 'not-a-reference', DocumentScanStatus::Clean, '', self::NOW);
    }

    private function pendingDocument(): ReferralDocument
    {
        return $this->documents->create(
            new ReferralDocument(
                id: 0,
                documentRef: bin2hex(random_bytes(16)),
                referralId: $this->referral->id,
                kind: DocumentKind::ReferralLetter,
                originalName: 'letter.pdf',
                mimeType: 'application/pdf',
                fileExtension: 'pdf',
                byteSize: 20,
                sha256: str_repeat('b', 64),
                storageKey: StorageKey::generate(),
                scanStatus: DocumentScanStatus::Pending,
                accessClass: DocumentKind::ReferralLetter->accessClass(),
                uploadedByUserId: 7,
                createdAtUtc: self::NOW,
            ),
            7,
            self::NOW,
        );
    }

    private function admin(): User
    {
        return new User(1, 'admin@example.test', 'Admin', Role::Admin, true);
    }

    private function staff(): User
    {
        return new User(7, 'clerk@example.test', 'Clerk', Role::Staff, true);
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
