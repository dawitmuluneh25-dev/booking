<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One stored file attached to a referral.
 *
 * `documentRef` is the only identifier that may appear in a URL; `storageKey` is
 * the private name of the bytes and never leaves the server. The two are
 * separate columns precisely so that leaking a link cannot leak a path.
 *
 * The `sha256` was computed from the stored bytes, so an integrity check can
 * detect a file changed underneath the record (DOC-009). It is never recomputed
 * from anything the client sends.
 */
final readonly class ReferralDocument
{
    public function __construct(
        public int $id,
        public string $documentRef,
        public int $referralId,
        public DocumentKind $kind,
        public string $originalName,
        public string $mimeType,
        public ?string $fileExtension,
        public int $byteSize,
        public string $sha256,
        public StorageKey $storageKey,
        public DocumentScanStatus $scanStatus,
        public DocumentAccessClass $accessClass,
        public int $uploadedByUserId,
        public string $createdAtUtc,
        public ?string $scanCheckedAtUtc = null,
        public ?string $scanNote = null,
        public ?int $verifiedByUserId = null,
        public ?string $verifiedAtUtc = null,
        public ?string $redactedAtUtc = null,
        public ?string $deletedAtUtc = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::number($row, 'id'),
            documentRef: self::text($row, 'document_ref'),
            referralId: self::number($row, 'referral_id'),
            kind: DocumentKind::tryFrom(self::text($row, 'kind')) ?? DocumentKind::Other,
            originalName: self::text($row, 'original_name'),
            mimeType: self::text($row, 'mime_type'),
            fileExtension: self::nullable($row, 'file_extension'),
            byteSize: self::number($row, 'byte_size'),
            sha256: self::text($row, 'sha256'),
            storageKey: StorageKey::fromString(self::text($row, 'storage_key')),
            scanStatus: DocumentScanStatus::tryFrom(self::text($row, 'scan_status'))
                ?? DocumentScanStatus::Pending,
            accessClass: DocumentAccessClass::tryFrom(self::text($row, 'access_class'))
                ?? DocumentAccessClass::ClinicalPrivate,
            uploadedByUserId: self::number($row, 'uploaded_by_user_id'),
            createdAtUtc: self::text($row, 'created_at'),
            scanCheckedAtUtc: self::nullable($row, 'scan_checked_at'),
            scanNote: self::nullable($row, 'scan_note'),
            verifiedByUserId: is_numeric($row['verified_by_user_id'] ?? null)
                ? (int) $row['verified_by_user_id']
                : null,
            verifiedAtUtc: self::nullable($row, 'verified_at'),
            redactedAtUtc: self::nullable($row, 'redacted_at'),
            deletedAtUtc: self::nullable($row, 'deleted_at'),
        );
    }

    public function withIdentity(int $id): self
    {
        return $this->copyWith(id: $id);
    }

    public function withScanVerdict(
        DocumentScanStatus $status,
        int $reviewerUserId,
        string $note,
        string $checkedAtUtc,
    ): self {
        return $this->copyWith(
            scanStatus: $status,
            scanCheckedAtUtc: $checkedAtUtc,
            scanNote: $note === '' ? null : $note,
            verifiedByUserId: $reviewerUserId,
            verifiedAtUtc: $checkedAtUtc,
        );
    }

    public function withDeletedAt(string $deletedAtUtc): self
    {
        return $this->copyWith(deletedAtUtc: $deletedAtUtc);
    }

    /** Whether the bytes may be delivered to an authorised reader right now. */
    public function isServable(): bool
    {
        return $this->deletedAtUtc === null && $this->scanStatus->isServable();
    }

    public function isRedacted(): bool
    {
        return $this->redactedAtUtc !== null;
    }

    private function copyWith(
        ?int $id = null,
        ?DocumentScanStatus $scanStatus = null,
        ?string $scanCheckedAtUtc = null,
        ?string $scanNote = null,
        ?int $verifiedByUserId = null,
        ?string $verifiedAtUtc = null,
        ?string $deletedAtUtc = null,
    ): self {
        return new self(
            id: $id ?? $this->id,
            documentRef: $this->documentRef,
            referralId: $this->referralId,
            kind: $this->kind,
            originalName: $this->originalName,
            mimeType: $this->mimeType,
            fileExtension: $this->fileExtension,
            byteSize: $this->byteSize,
            sha256: $this->sha256,
            storageKey: $this->storageKey,
            scanStatus: $scanStatus ?? $this->scanStatus,
            accessClass: $this->accessClass,
            uploadedByUserId: $this->uploadedByUserId,
            createdAtUtc: $this->createdAtUtc,
            scanCheckedAtUtc: $scanCheckedAtUtc ?? $this->scanCheckedAtUtc,
            scanNote: $scanNote ?? $this->scanNote,
            verifiedByUserId: $verifiedByUserId ?? $this->verifiedByUserId,
            verifiedAtUtc: $verifiedAtUtc ?? $this->verifiedAtUtc,
            redactedAtUtc: $this->redactedAtUtc,
            deletedAtUtc: $deletedAtUtc ?? $this->deletedAtUtc,
        );
    }

    /** @param array<string, mixed> $row */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => '',
        };
    }

    /** @param array<string, mixed> $row */
    private static function nullable(array $row, string $key): ?string
    {
        $value = self::text($row, $key);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $row */
    private static function number(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
