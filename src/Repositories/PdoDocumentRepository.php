<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\AccessOutcome;
use App\Domain\DocumentAccessEvent;
use App\Domain\DocumentAction;
use App\Domain\DocumentRepository;
use App\Domain\DocumentReviewException;
use App\Domain\DocumentScanStatus;
use App\Domain\ReferralDocument;
use App\Domain\Transaction;
use PDO;

/**
 * MySQL implementation of the document boundary.
 *
 * `create` writes the row and its upload event in one unit of work, because the
 * event needs the id the insert produces and because a document with no record of
 * who stored it is not something this system keeps (SEC 14.1).
 *
 * `recordScanVerdict` is guarded by `WHERE id = ? AND scan_status = 'pending'`.
 * Two reviewers looking at the same file therefore produce one decision and one
 * visible refusal, instead of a last-write-wins overwrite of a clinical
 * judgement.
 */
final class PdoDocumentRepository implements DocumentRepository
{
    private const COLUMNS = 'id, document_ref, referral_id, kind, original_name, mime_type,
        file_extension, byte_size, sha256, scan_status, scan_checked_at, scan_note,
        access_class, verified_by_user_id, verified_at, storage_key, uploaded_by_user_id,
        created_at, redacted_at, deleted_at';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Transaction $transaction,
    ) {
    }

    public function create(
        ReferralDocument $document,
        int $uploaderUserId,
        string $occurredAtUtc,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ReferralDocument {
        return $this->transaction->run(function () use (
            $document,
            $uploaderUserId,
            $occurredAtUtc,
            $ipAddress,
            $userAgent,
        ): ReferralDocument {
            $insert = $this->pdo->prepare(
                'INSERT INTO referral_documents
                    (document_ref, referral_id, kind, original_name, mime_type, file_extension,
                     byte_size, sha256, scan_status, access_class, storage_key,
                     uploaded_by_user_id, created_at)
                 VALUES
                    (:document_ref, :referral_id, :kind, :original_name, :mime_type, :file_extension,
                     :byte_size, :sha256, :scan_status, :access_class, :storage_key,
                     :uploaded_by_user_id, :created_at)'
            );

            $insert->execute([
                'document_ref' => $document->documentRef,
                'referral_id' => $document->referralId,
                'kind' => $document->kind->value,
                'original_name' => $document->originalName,
                'mime_type' => $document->mimeType,
                'file_extension' => $document->fileExtension,
                'byte_size' => $document->byteSize,
                'sha256' => $document->sha256,
                'scan_status' => $document->scanStatus->value,
                'access_class' => $document->accessClass->value,
                'storage_key' => $document->storageKey->value,
                'uploaded_by_user_id' => $uploaderUserId,
                'created_at' => $occurredAtUtc,
            ]);

            $stored = $document->withIdentity((int) $this->pdo->lastInsertId());

            $this->recordAccess(new DocumentAccessEvent(
                documentId: $stored->id,
                userId: $uploaderUserId,
                action: DocumentAction::Upload,
                outcome: AccessOutcome::Allowed,
                occurredAtUtc: $occurredAtUtc,
                ipAddress: $ipAddress,
                userAgent: $userAgent === null ? null : mb_substr($userAgent, 0, 190),
            ));

            return $stored;
        });
    }

    public function findByRef(string $documentRef): ?ReferralDocument
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM referral_documents WHERE document_ref = :ref'
        );
        $statement->execute(['ref' => $documentRef]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return ReferralDocument::fromRow($row);
    }

    /** @return list<ReferralDocument> */
    public function listForReferral(int $referralId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM referral_documents WHERE referral_id = :id ORDER BY id'
        );
        $statement->execute(['id' => $referralId]);

        $documents = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $documents[] = ReferralDocument::fromRow($row);
        }

        return $documents;
    }

    public function recordScanVerdict(
        ReferralDocument $document,
        DocumentScanStatus $status,
        int $reviewedByUserId,
        string $note,
        string $occurredAtUtc,
    ): ReferralDocument {
        return $this->transaction->run(function () use (
            $document,
            $status,
            $reviewedByUserId,
            $note,
            $occurredAtUtc,
        ): ReferralDocument {
            $update = $this->pdo->prepare(
                "UPDATE referral_documents
                    SET scan_status = :status,
                        scan_checked_at = :checked_at,
                        scan_note = :note,
                        verified_by_user_id = :reviewer,
                        verified_at = :verified_at
                  WHERE id = :id
                    AND scan_status = 'pending'"
            );

            $update->execute([
                'status' => $status->value,
                'checked_at' => $occurredAtUtc,
                'note' => $note === '' ? null : $note,
                'reviewer' => $reviewedByUserId,
                'verified_at' => $occurredAtUtc,
                'id' => $document->id,
            ]);

            if ($update->rowCount() !== 1) {
                $current = $this->findByRef($document->documentRef);

                throw new DocumentReviewException(
                    // `??` already absorbs a null dereference, so the nullsafe
                    // operator here would be decoration.
                    $current->scanStatus ?? DocumentScanStatus::Pending,
                    'Another reviewer recorded a verdict first.',
                );
            }

            return $this->findByRef($document->documentRef) ?? $document->withScanVerdict(
                $status,
                $reviewedByUserId,
                $note,
                $occurredAtUtc,
            );
        });
    }

    public function recordAccess(DocumentAccessEvent $event): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO document_access_events
                (document_id, user_id, action, outcome, occurred_at, ip_address, user_agent, reason)
             VALUES
                (:document_id, :user_id, :action, :outcome, :occurred_at, :ip_address, :user_agent, :reason)'
        );

        $insert->bindValue('document_id', $event->documentId, PDO::PARAM_INT);
        $insert->bindValue('user_id', $event->userId, $event->userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $insert->bindValue('action', $event->action->value);
        $insert->bindValue('outcome', $event->outcome->value);
        $insert->bindValue('occurred_at', $event->occurredAtUtc);
        $insert->bindValue(
            'ip_address',
            $event->packedIp(),
            $event->packedIp() === null ? PDO::PARAM_NULL : PDO::PARAM_LOB,
        );
        $insert->bindValue(
            'user_agent',
            $event->userAgent === null ? null : mb_substr($event->userAgent, 0, 190),
            $event->userAgent === null ? PDO::PARAM_NULL : PDO::PARAM_STR,
        );
        $insert->bindValue(
            'reason',
            $event->reason === null ? null : mb_substr($event->reason, 0, 190),
            $event->reason === null ? PDO::PARAM_NULL : PDO::PARAM_STR,
        );

        $insert->execute();
    }

    /** @return list<array{action: string, outcome: string, occurred_at: string}> */
    public function recentAccessFor(int $documentId, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT action, outcome, occurred_at
               FROM document_access_events
              WHERE document_id = :id
              ORDER BY occurred_at DESC, id DESC
              LIMIT :limit'
        );
        $statement->bindValue('id', $documentId, PDO::PARAM_INT);
        $statement->bindValue('limit', max(1, min($limit, 200)), PDO::PARAM_INT);
        $statement->execute();

        $events = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $events[] = [
                'action' => self::text($row['action'] ?? null),
                'outcome' => self::text($row['outcome'] ?? null),
                'occurred_at' => self::text($row['occurred_at'] ?? null),
            ];
        }

        return $events;
    }

    /** Reads a value the driver may return as any scalar, without casting mixed. */
    private static function text(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => '',
        };
    }
}
