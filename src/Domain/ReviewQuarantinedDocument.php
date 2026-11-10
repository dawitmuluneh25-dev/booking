<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Record the outcome of a quarantine review, which is what releases a file.
 *
 * This is the documented compensating control for a deployment with no malware
 * scanner (HAK-0904): a named authorised person looks at the file and says so,
 * in a row that records who decided, when, and with what note. It is deliberately
 * not automatic - an upload cannot release itself.
 *
 * `pending` is not an acceptable verdict here; only `clean`, `infected` or
 * `error` are. A reviewer who is unsure records `error`, which keeps the file
 * shut, rather than leaving it open by omission.
 */
final class ReviewQuarantinedDocument
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentAccessService $access,
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {
    }

    public function execute(
        User $reviewer,
        string $documentRef,
        DocumentScanStatus $verdict,
        string $note,
        string $occurredAtUtc,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ReferralDocument {
        if (!$reviewer->can(Permission::DocumentQuarantineManage)) {
            throw new AuthorisationException($reviewer->role, Permission::DocumentQuarantineManage);
        }

        if (!$verdict->isReviewVerdict()) {
            throw new ValidationException([
                'verdict' => 'A review must release the document, quarantine it, or record an error.',
            ]);
        }

        $document = $this->documents->findByRef($documentRef);

        if ($document === null) {
            throw new DocumentNotFoundException();
        }

        if ($document->scanStatus !== DocumentScanStatus::Pending) {
            throw new DocumentReviewException(
                $document->scanStatus,
                'Reload the document and check its current state.',
            );
        }

        $note = trim($note);
        if ($verdict !== DocumentScanStatus::Clean && $note === '') {
            throw new ValidationException([
                'note' => 'Record why the document is being withheld.',
            ]);
        }

        return $this->transaction->run(function () use (
            $document,
            $verdict,
            $reviewer,
            $note,
            $occurredAtUtc,
            $ipAddress,
            $userAgent,
        ): ReferralDocument {
            $reviewed = $this->documents->recordScanVerdict(
                $document,
                $verdict,
                $reviewer->id,
                mb_substr($note, 0, 190),
                $occurredAtUtc,
            );

            $this->access->record(
                $reviewed,
                $reviewer,
                DocumentAction::QuarantineReview,
                AccessOutcome::Allowed,
                $occurredAtUtc,
                $verdict->value,
                $ipAddress,
                $userAgent,
            );

            // The verdict is the release decision, so it is the thing the unified
            // trail records. The object reference is the opaque handle, never the
            // filename: a released document's name can name a patient's diagnosis.
            $this->audit->record(AuditEntry::byActor(
                actor: new Actor($reviewer->id, $reviewer->role),
                action: $verdict === DocumentScanStatus::Clean
                    ? AuditAction::DocumentReleased
                    : AuditAction::DocumentWithheld,
                objectType: 'document',
                objectRef: $reviewed->documentRef,
                after: ['scan_status' => $verdict->value],
                occurredAtUtc: $occurredAtUtc,
            ));

            return $reviewed;
        });
    }
}
