<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The collaborators one document screen needs, resolved together.
 *
 * A controller that took nine arguments would be unreadable, and a controller
 * that reached into the container would be untestable. This is the smallest
 * bundle that lets the document routes be exercised against in-memory doubles
 * without a database, a filesystem or a session.
 */
final readonly class DocumentWorkspace
{
    public function __construct(
        public ReferralRepository $referrals,
        public DocumentRepository $documents,
        public DocumentStorage $storage,
        public ReferralAccessResolver $access,
        public UploadReferralDocument $upload,
        public ReviewQuarantinedDocument $review,
        public DocumentAccessService $documentAccess,
        public ReferralTrackingLink $tracking,
        public DocumentTypes $types,
    ) {
    }
}
