<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The one place a document is resolved, authorised, checked and logged.
 *
 * Everything the download controller does is here, in this order, because the
 * order is the control (SEC 14.3):
 *
 * - resolve the record from the opaque reference in the URL, server-side;
 * - apply the parent-object policy, so a staff account that may read referrals
 *   still cannot read somebody else's patient's file;
 * - refuse a file that is not released;
 * - write the access event, allowed or denied, before the bytes move;
 * - hand back the record so the caller can stream it without ever seeing a path.
 *
 * A denial writes a `denied` event and then raises the same not-found as an
 * unknown reference. The audit trail knows the difference; the caller does not
 * (DOC-06).
 */
final class DocumentAccessService
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly ReferralRepository $referrals,
        private readonly ReferralAccessResolver $access,
    ) {
    }

    public function open(
        User $user,
        string $documentRef,
        DocumentAction $action,
        string $occurredAtUtc,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ReferralDocument {
        $permission = $action === DocumentAction::Download
            ? Permission::DocumentDownload
            : Permission::DocumentReadMetadata;

        if (!$user->can($permission)) {
            throw new AuthorisationException($user->role, $permission);
        }

        $document = $this->documents->findByRef($documentRef);

        if ($document === null) {
            throw new DocumentNotFoundException();
        }

        $referral = $this->referrals->findById($document->referralId);

        if ($referral === null) {
            throw new DocumentNotFoundException();
        }

        if (!$this->access->canView($user, $referral)) {
            $this->record(
                $document,
                $user,
                $action,
                AccessOutcome::Denied,
                $occurredAtUtc,
                'actor cannot see the parent referral',
                $ipAddress,
                $userAgent,
            );

            throw new DocumentNotFoundException();
        }

        // Metadata is readable while a file is quarantined - a clinician needs to
        // know an attachment exists and is under review. Only the bytes are held.
        if ($action === DocumentAction::Download && !$document->isServable()) {
            $this->record(
                $document,
                $user,
                $action,
                AccessOutcome::Denied,
                $occurredAtUtc,
                'document is not released: ' . $document->scanStatus->value,
                $ipAddress,
                $userAgent,
            );

            throw new DocumentQuarantinedException($document->scanStatus);
        }

        $this->record($document, $user, $action, AccessOutcome::Allowed, $occurredAtUtc, null, $ipAddress, $userAgent);

        return $document;
    }

    /**
     * Write one access event.
     *
     * Public so a reviewed verdict or a future deletion request can be recorded
     * through the same path, with the same rule that no filename and no clinical
     * text enters the audit table.
     */
    public function record(
        ReferralDocument $document,
        ?User $user,
        DocumentAction $action,
        AccessOutcome $outcome,
        string $occurredAtUtc,
        ?string $reason = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        $this->documents->recordAccess(new DocumentAccessEvent(
            documentId: $document->id,
            userId: $user === null ? null : $user->id,
            action: $action,
            outcome: $outcome,
            occurredAtUtc: $occurredAtUtc,
            ipAddress: $ipAddress,
            userAgent: $userAgent === null ? null : mb_substr($userAgent, 0, 190),
            reason: $reason === null ? null : mb_substr($reason, 0, 190),
        ));
    }
}
