<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\DocumentScanner;
use App\Domain\ScanResult;

/**
 * A content inspector whose answer the test chooses.
 *
 * Used to exercise the decision branches in `UploadReferralDocument` - a PNG that
 * claims to be a PDF, an unrecognised blob - without depending on what libmagic
 * happens to say about a three-byte fixture. `FileSignatureScannerTest` covers
 * the real implementation.
 */
final class FakeDocumentScanner implements DocumentScanner
{
    /** @var list<string> */
    private array $inspected = [];

    public function __construct(private ?string $detectedMime)
    {
    }

    public static function detecting(string $mime): self
    {
        return new self($mime);
    }

    public static function rejecting(): self
    {
        return new self(null);
    }

    public function inspect(string $path): ScanResult
    {
        $this->inspected[] = $path;

        return new ScanResult($this->detectedMime, $this->detectedMime === null ? 'rejected by test' : 'detected');
    }

    /** @return list<string> */
    public function inspected(): array
    {
        return $this->inspected;
    }
}
