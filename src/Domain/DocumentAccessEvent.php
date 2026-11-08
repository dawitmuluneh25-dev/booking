<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One recorded attempt to touch a private document (DDS `document_access_events`).
 *
 * Deliberately carries no filename, no storage key and no clinical text: an
 * audit record that duplicates the content it audits becomes a second place the
 * PHI has to be protected (SEC 13.2, DOC-010).
 */
final readonly class DocumentAccessEvent
{
    public function __construct(
        public int $documentId,
        public ?int $userId,
        public DocumentAction $action,
        public AccessOutcome $outcome,
        public string $occurredAtUtc,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $reason = null,
    ) {
    }

    public static function allowed(
        ReferralDocument $document,
        ?int $userId,
        DocumentAction $action,
        string $occurredAtUtc,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): self {
        return new self(
            $document->id,
            $userId,
            $action,
            AccessOutcome::Allowed,
            $occurredAtUtc,
            $ipAddress,
            $userAgent,
        );
    }

    public static function denied(
        int $documentId,
        ?int $userId,
        DocumentAction $action,
        string $occurredAtUtc,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): self {
        return new self(
            $documentId,
            $userId,
            $action,
            AccessOutcome::Denied,
            $occurredAtUtc,
            $ipAddress,
            $userAgent,
            $reason,
        );
    }

    /**
     * Packed as bytes so the column can hold IPv4 and IPv6 without a second
     * format decision at query time. An unparsable address is stored as null
     * rather than as the text "unknown", which would look like a real address.
     */
    public function packedIp(): ?string
    {
        if ($this->ipAddress === null) {
            return null;
        }

        $packed = @inet_pton($this->ipAddress);

        return $packed === false ? null : $packed;
    }
}
