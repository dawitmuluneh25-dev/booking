<?php

declare(strict_types=1);

namespace App\Domain;

/** What was attempted against a document (DDS `document_access_events.action`). */
enum DocumentAction: string
{
    case View = 'view';
    case Download = 'download';
    case Upload = 'upload';
    case DeleteRequest = 'delete_request';
    case QuarantineReview = 'quarantine_review';

    public function label(): string
    {
        return match ($this) {
            self::View => 'Viewed',
            self::Download => 'Downloaded',
            self::Upload => 'Uploaded',
            self::DeleteRequest => 'Deletion requested',
            self::QuarantineReview => 'Review recorded',
        };
    }
}
