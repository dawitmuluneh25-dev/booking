<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\StorageKey;
use App\Storage\FileSignatureScanner;
use App\Storage\FilesystemDocumentStorage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * DOC-01 and DOC-04 at the storage layer.
 *
 * The key is the only input that decides a path, so the interesting cases are the
 * ones where somebody tries to make it something other than 32 hex characters,
 * and the one where a second write would silently replace a stored document.
 */
final class DocumentStorageTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'doc-store-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        $this->temporaryFiles = [];

        if (is_dir($this->root)) {
            $this->removeTree($this->root);
        }
    }

    public function testBytesRoundTripUnderTheGeneratedKey(): void
    {
        $storage = new FilesystemDocumentStorage($this->root);
        $key = StorageKey::generate();

        $storage->write($key, $this->source('%PDF-1.4 hello'));

        self::assertTrue($storage->exists($key));
        self::assertSame('%PDF-1.4 hello', $storage->read($key));
        self::assertTrue($storage->delete($key));
        self::assertFalse($storage->exists($key));
    }

    public function testAKeyIsNeverUsedTwice(): void
    {
        $storage = new FilesystemDocumentStorage($this->root);
        $key = StorageKey::generate();

        $storage->write($key, $this->source('first'));

        $this->expectException(RuntimeException::class);

        try {
            $storage->write($key, $this->source('second'));
        } finally {
            self::assertSame('first', $storage->read($key));
        }
    }

    public function testATraversalStringCannotBecomeAKey(): void
    {
        $candidates = [
            '../../public/index.php',
            '..',
            str_repeat('a', 31),
            str_repeat('A', 32),
            str_repeat('g', 32),
            "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\x00",
        ];

        $refused = 0;

        foreach ($candidates as $candidate) {
            try {
                StorageKey::fromString($candidate);
            } catch (InvalidArgumentException) {
                $refused++;
            }
        }

        self::assertSame(
            count($candidates),
            $refused,
            'A storage key accepted something that is not 32 hex characters.',
        );

        // …and the validator is not simply refusing everything.
        self::assertSame(str_repeat('a', 32), StorageKey::fromString(str_repeat('a', 32))->value);
    }

    public function testMissingBytesAreNotSilentlyEmpty(): void
    {
        $storage = new FilesystemDocumentStorage($this->root);

        $this->expectException(RuntimeException::class);

        $storage->read(StorageKey::generate());
    }

    public function testTheSignatureScannerRefusesAScriptRenamedAsAPdf(): void
    {
        $scanner = new FileSignatureScanner();

        $result = $scanner->inspect($this->source('<?php echo "not a pdf";'));

        self::assertNull($result->detectedMime);
    }

    public function testTheSignatureScannerReadsARealPdfHeader(): void
    {
        if (!class_exists(\finfo::class)) {
            self::markTestSkipped('fileinfo is not available on this host.');
        }

        $scanner = new FileSignatureScanner();

        $result = $scanner->inspect($this->source("%PDF-1.7\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n"));

        self::assertSame('application/pdf', $result->detectedMime);
    }

    public function testTheSignatureScannerRefusesAnEmptyFile(): void
    {
        $scanner = new FileSignatureScanner();

        self::assertNull($scanner->inspect($this->source(''))->detectedMime);
    }

    private function source(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'doc-src');
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function removeTree(string $directory): void
    {
        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
