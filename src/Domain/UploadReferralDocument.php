<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;
use Throwable;

/**
 * Store a file against a referral, privately and type-checked.
 *
 * The order of operations is the substance of this class:
 *
 * 1. authority - the actor must hold `document.upload` *and* be able to see the
 *    referral (SEC 14.1 step 1);
 * 2. request validation - size, extension, and the state of the referral itself;
 * 3. content inspection - the bytes must be the type the extension claims
 *    (DOC-03), measured before anything is written;
 * 4. store - a random key, outside the web root, never overwriting (DOC-04);
 * 5. record - the row and its upload audit event in one transaction, so a file
 *    with no record cannot survive;
 * 6. compensate - if the database write fails, the stored bytes are removed
 *    rather than left behind as an orphan nobody can see or delete.
 *
 * The document is always born `pending`: nothing reaches a clinician until an
 * authorised reviewer releases it (DOC-05).
 */
final class UploadReferralDocument
{
    /** SEC 14.1: the application cap, below the measured 64 MiB PHP ceiling. */
    public const DEFAULT_MAX_BYTES = 15 * 1024 * 1024;

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentStorage $storage,
        private readonly DocumentScanner $scanner,
        private readonly ReferralAccessResolver $access,
        private readonly Transaction $transaction,
        private readonly DocumentTypes $types = new DocumentTypes(),
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {
    }

    public function execute(
        User $actor,
        Referral $referral,
        DocumentKind $kind,
        ?UploadedFile $file,
        string $occurredAtUtc,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ReferralDocument {
        $this->authorise($actor, $referral);
        $this->assertReferralAcceptsDocuments($referral);

        if ($file === null) {
            throw new ValidationException(['file' => 'Choose a file to upload.']);
        }

        if ($file->hasError()) {
            throw new ValidationException(['file' => $file->errorMessage($this->maxBytes)]);
        }

        $this->assertWithinSizeLimit($file);
        $extension = $this->assertAllowedExtension($file);
        $mimeType = $this->assertContentMatchesExtension($file, $extension);
        $hash = $this->hashOf($file->temporaryPath);

        $storageKey = StorageKey::generate();
        $this->storage->write($storageKey, $file->temporaryPath);

        try {
            $document = new ReferralDocument(
                id: 0,
                documentRef: bin2hex(random_bytes(16)),
                referralId: $referral->id,
                kind: $kind,
                originalName: $file->safeName(),
                mimeType: $mimeType,
                fileExtension: $extension,
                byteSize: $file->byteSize,
                sha256: $hash,
                storageKey: $storageKey,
                scanStatus: DocumentScanStatus::Pending,
                accessClass: $kind->accessClass(),
                uploadedByUserId: $actor->id,
                createdAtUtc: $occurredAtUtc,
            );

            return $this->transaction->run(
                fn (): ReferralDocument => $this->documents->create(
                    $document,
                    $actor->id,
                    $occurredAtUtc,
                    $ipAddress,
                    $userAgent,
                ),
            );
        } catch (Throwable $exception) {
            $this->storage->delete($storageKey);

            throw $exception;
        }
    }

    private function authorise(User $actor, Referral $referral): void
    {
        if (!$actor->can(Permission::DocumentUpload)) {
            throw new AuthorisationException($actor->role, Permission::DocumentUpload);
        }

        if (!$this->access->canView($actor, $referral)) {
            throw AuthorisationException::forObject(
                $actor->role,
                Permission::DocumentUpload,
                'This referral is not yours.',
            );
        }
    }

    /**
     * A closed referral is a record, not a working file.
     *
     * Accepting new attachments after the case ends would let the evidence behind
     * a decision change after the fact, which is what the append-only history
     * exists to prevent.
     */
    private function assertReferralAcceptsDocuments(Referral $referral): void
    {
        if ($referral->status->isTerminal()) {
            throw new ValidationException([
                'referral' => 'This referral is closed; documents can no longer be added.',
            ]);
        }
    }

    private function assertWithinSizeLimit(UploadedFile $file): void
    {
        if ($file->byteSize > $this->maxBytes) {
            throw new ValidationException([
                'file' => 'That file is larger than the ' . $this->describedLimit() . ' limit.',
            ]);
        }

        if ($file->byteSize <= 0) {
            throw new ValidationException(['file' => 'That file is empty.']);
        }
    }

    private function assertAllowedExtension(UploadedFile $file): string
    {
        $extension = $file->extension();

        if ($extension === '' || !$this->types->allows($extension)) {
            throw new ValidationException([
                'file' => 'Only ' . $this->types->describe() . ' documents are accepted.',
            ]);
        }

        return $extension;
    }

    /**
     * DOC-03: the declared type is not evidence, the bytes are.
     *
     * The stored MIME type comes from the catalogue for this extension, not from
     * the browser and not from the scanner's free-text output, so the row can only
     * ever claim a type this application chose to accept.
     */
    private function assertContentMatchesExtension(UploadedFile $file, string $extension): string
    {
        $expected = $this->types->mimeTypesFor($extension);
        $result = $this->scanner->inspect($file->temporaryPath);
        $detected = $result->detectedMime === null
            ? null
            : strtolower(trim(explode(';', $result->detectedMime)[0]));

        if ($detected === null || !in_array($detected, $expected, true)) {
            throw new ValidationException([
                'file' => 'The file content does not match its ' . strtoupper($extension) . ' extension.',
            ]);
        }

        $canonical = $this->types->canonicalMime($extension);

        if ($canonical === null) {
            throw new ValidationException(['file' => 'That document type is not configured.']);
        }

        return $canonical;
    }

    /**
     * The cap in the unit a person would use for it.
     *
     * `intdiv($bytes, 1048576)` reports a 1 KiB limit as "0 MiB", which is a
     * message that helps nobody.
     */
    private function describedLimit(): string
    {
        if ($this->maxBytes >= 1024 * 1024) {
            $mib = rtrim(rtrim(number_format($this->maxBytes / (1024 * 1024), 1, '.', ''), '0'), '.');

            return $mib . ' MiB';
        }

        return (int) ceil($this->maxBytes / 1024) . ' KiB';
    }

    private function hashOf(string $path): string
    {
        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        return $hash;
    }
}
