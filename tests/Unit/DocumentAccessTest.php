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
use App\Domain\DocumentScanStatus;
use App\Domain\PatientRelationshipRepository;
use App\Domain\Referral;
use App\Domain\ReferralAccessPolicy;
use App\Domain\ReferralAccessResolver;
use App\Domain\ReferralDocument;
use App\Domain\ReferralStatus;
use App\Domain\Role;
use App\Domain\StorageKey;
use App\Domain\User;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDocumentRepository;
use Tests\Support\InMemoryPhysicianProfileRepository;
use Tests\Support\InMemoryReferralRepository;

/**
 * Phase 4 criterion 2 and DOC-05/DOC-06.
 *
 * A document the actor may not see answers exactly like one that does not exist,
 * and the difference is written to the audit table rather than to the screen.
 */
final class DocumentAccessTest extends TestCase
{
    private const NOW = '2026-11-09 11:00:00';

    private InMemoryDocumentRepository $documents;

    private DocumentAccessService $service;

    private Referral $referral;

    protected function setUp(): void
    {
        $this->documents = new InMemoryDocumentRepository();

        $referrals = new InMemoryReferralRepository();
        $this->referral = $referrals->create(
            $this->referralEntity(),
            new Actor(7, Role::Staff),
            self::NOW,
        );

        $this->service = new DocumentAccessService(
            $this->documents,
            $referrals,
            new ReferralAccessResolver(
                new ReferralAccessPolicy(),
                new InMemoryPhysicianProfileRepository(),
                $this->noRelationships(),
            ),
        );
    }

    public function testStaffMayOpenADocumentThatHasBeenReleased(): void
    {
        $document = $this->storeDocument(DocumentScanStatus::Clean);

        $opened = $this->service->open(
            $this->staff(),
            $document->documentRef,
            DocumentAction::Download,
            self::NOW,
            '127.0.0.1',
            'PHPUnit',
        );

        self::assertSame($document->id, $opened->id);
    }

    public function testAnAllowedOpenIsAudited(): void
    {
        $document = $this->storeDocument(DocumentScanStatus::Clean);

        $this->service->open($this->staff(), $document->documentRef, DocumentAction::Download, self::NOW);

        $events = $this->documents->recentAccessFor($document->id, 10);

        self::assertSame('download', $events[0]['action']);
        self::assertSame('allowed', $events[0]['outcome']);
    }

    /**
     * A denial is logged and answered as "does not exist".
     *
     * The stranger here is a physician rather than a patient: a physician can reach
     * the object check and be refused by it, whereas a patient is stopped earlier at
     * the permission gate (ADR-016). Keeping the patient here would assert an audit
     * trail for a request that never gets that far.
     */
    public function testADeniedReadIsLoggedAndAnsweredAsNotFound(): void
    {
        $document = $this->storeDocument(DocumentScanStatus::Clean);
        $stranger = new User(404, 'someone@example.test', 'Someone', Role::Physician, true);

        try {
            $this->service->open($stranger, $document->documentRef, DocumentAction::Download, self::NOW);
            self::fail('A stranger opened somebody else\'s document.');
        } catch (DocumentNotFoundException) {
            $events = $this->documents->recentAccessFor($document->id, 10);

            self::assertSame('denied', $events[0]['outcome']);
            self::assertSame('download', $events[0]['action']);
        }
    }

    public function testAPhysicianWithoutAVerifiedProfileSeesNothing(): void
    {
        $document = $this->storeDocument(DocumentScanStatus::Clean);
        $physician = new User(55, 'referrer@example.test', 'Referrer', Role::Physician, true);

        $this->expectException(DocumentNotFoundException::class);

        $this->service->open($physician, $document->documentRef, DocumentAction::Download, self::NOW);
    }

    /**
     * A patient is refused at the permission gate, not at the object (ADR-016).
     *
     * This replaced two tests that proved a patient could read only their own
     * documents. That property is no longer reachable — no patient can sign in — and
     * an unreachable path kept green is a claim nobody can act on. Ownership is still
     * proven where it still means something: ReferralAccessPolicyTest asserts a
     * patient sees only referrals they submitted, and the physician-stranger case
     * above proves an object-level denial is answered as "does not exist".
     */
    public function testAPatientIsRefusedAtThePermissionGate(): void
    {
        $document = $this->storeDocument(DocumentScanStatus::Clean);
        $patient = new User(3, 'patient@example.test', 'Patient', Role::Patient, true);

        $this->expectException(AuthorisationException::class);

        $this->service->open($patient, $document->documentRef, DocumentAction::Download, self::NOW);
    }

    public function testAFileStillInQuarantineIsNotServed(): void
    {
        $document = $this->storeDocument(DocumentScanStatus::Pending);

        try {
            $this->service->open($this->staff(), $document->documentRef, DocumentAction::Download, self::NOW);
            self::fail('A quarantined file was served.');
        } catch (DocumentQuarantinedException $exception) {
            self::assertSame(DocumentScanStatus::Pending, $exception->status);

            $events = $this->documents->recentAccessFor($document->id, 10);
            self::assertSame('denied', $events[0]['outcome']);
        }
    }

    public function testMetadataIsReadableWhileTheFileIsQuarantined(): void
    {
        // A clinician needs to know an attachment exists and is under review; only
        // the bytes are withheld.
        $document = $this->storeDocument(DocumentScanStatus::Pending);

        $viewed = $this->service->open($this->staff(), $document->documentRef, DocumentAction::View, self::NOW);

        self::assertSame(DocumentScanStatus::Pending, $viewed->scanStatus);
    }

    public function testAnUnknownReferenceIsNotFoundAndWritesNothing(): void
    {
        try {
            $this->service->open($this->staff(), 'nope', DocumentAction::Download, self::NOW);
            self::fail('An invented document reference resolved.');
        } catch (DocumentNotFoundException) {
            self::assertSame([], $this->documents->events());
        }
    }

    public function testAnInfectedDocumentIsNeverServed(): void
    {
        $document = $this->storeDocument(DocumentScanStatus::Infected);

        $this->expectException(DocumentQuarantinedException::class);

        $this->service->open($this->staff(), $document->documentRef, DocumentAction::Download, self::NOW);
    }

    private function storeDocument(DocumentScanStatus $status): ReferralDocument
    {
        return $this->documents->create(
            new ReferralDocument(
                id: 0,
                documentRef: bin2hex(random_bytes(16)),
                referralId: $this->referral->id,
                kind: DocumentKind::ImagingReport,
                originalName: 'scan.pdf',
                mimeType: 'application/pdf',
                fileExtension: 'pdf',
                byteSize: 12,
                sha256: str_repeat('a', 64),
                storageKey: StorageKey::generate(),
                scanStatus: $status,
                accessClass: DocumentKind::ImagingReport->accessClass(),
                uploadedByUserId: 7,
                createdAtUtc: self::NOW,
            ),
            7,
            self::NOW,
        );
    }

    private function staff(): User
    {
        return new User(7, 'clerk@example.test', 'Clerk', Role::Staff, true);
    }

    private function referralEntity(): Referral
    {
        return new Referral(
            id: 0,
            reference: 'RF-2026-0009',
            status: ReferralStatus::AwaitingImaging,
            version: 1,
            patientName: 'Patient One',
            patientPhone: '+251911000001',
            patientDob: null,
            guardianName: null,
            guardianPhone: null,
            guardianRelationship: null,
            procedureCode: 'image_guided_biopsy',
            requestedUrgency: 'routine',
            clinicalSummary: 'Summary',
            targetSite: 'Liver',
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
