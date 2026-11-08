<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What content inspection actually found in a file.
 *
 * `detectedMime` is what the bytes are, not what the browser claimed. It can be
 * null when the content matches no signature this application accepts, which is
 * a rejection rather than an unknown-but-probably-fine.
 */
final readonly class ScanResult
{
    public function __construct(
        public ?string $detectedMime,
        public string $note = '',
    ) {
    }

    public function isRecognised(): bool
    {
        return $this->detectedMime !== null;
    }
}
