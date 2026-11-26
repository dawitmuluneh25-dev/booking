<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ClinicalDocumentRetentionService;
use App\Domain\RetentionPolicy;
use App\Domain\StorageKey;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryClinicalDocumentRetentionRepository;
use Tests\Support\InMemoryDocumentStorage;

/**
 * The document sweep's ordering rule, which is the only thing here that can lose data.
 *
 * Bytes first, then the row. If the order is reversed and the file delete fails, the row is redacted and
 * the file is still in storage with nothing left to enumerate it by — the row no longer names it. That
 * state is unrecoverable by design, so it gets a test rather than a comment.
 */
final class ClinicalDocumentRetentionServiceTest extends TestCase
{
    private const DAY = 86400;

    public function testBytesAreRemovedBeforeTheRowIsRedacted(): void
    {
        $key = $this->key();
        $storage = new InMemoryDocumentStorage();
        $storage->seed($key, 'patient letter');

        $repository = new InMemoryClinicalDocumentRetentionRepository([
            ['document_id' => 1, 'storage_key' => $key->value, 'created_at' => $this->daysAgo(2000)],
        ]);

        $service = new ClinicalDocumentRetentionService($repository, $storage, new RetentionPolicy(1825));
        $result = $service->purge('cli:test', 10, 5);

        self::assertSame(1, $result['documents']);
        self::assertSame(1, $result['files']);
        self::assertFalse($storage->exists($key), 'The bytes should be gone from storage.');
        self::assertSame([1], $repository->redacted);
        self::assertSame([1], $repository->markedDeleted);
    }

    public function testADocumentInsideItsPeriodIsLeftAlone(): void
    {
        $key = $this->key();
        $storage = new InMemoryDocumentStorage();
        $storage->seed($key, 'recent letter');

        $repository = new InMemoryClinicalDocumentRetentionRepository([
            ['document_id' => 2, 'storage_key' => $key->value, 'created_at' => $this->daysAgo(10)],
        ]);

        $result = (new ClinicalDocumentRetentionService($repository, $storage, new RetentionPolicy(1825)))
            ->purge('cli:test', 10, 5);

        self::assertSame(0, $result['documents']);
        self::assertTrue($storage->exists($key));
        self::assertSame([], $repository->runs, 'An empty sweep writes no run row.');
    }

    public function testAMissingFileStillRedactsTheRow(): void
    {
        // The bytes may already be gone — a previous partial run, or a manual removal. The filename is
        // still identifying, so the row is redacted; only the file counter stays at zero.
        $key = $this->key();
        $storage = new InMemoryDocumentStorage();

        $repository = new InMemoryClinicalDocumentRetentionRepository([
            ['document_id' => 3, 'storage_key' => $key->value, 'created_at' => $this->daysAgo(2000)],
        ]);

        $result = (new ClinicalDocumentRetentionService($repository, $storage, new RetentionPolicy(1825)))
            ->purge('cli:test', 10, 5);

        self::assertSame(1, $result['documents']);
        self::assertSame(0, $result['files'], 'No file was deleted because none was there.');
        self::assertSame([3], $repository->redacted);
    }

    public function testTheSweepStopsAtTheCeilingAndSaysMoreIsWaiting(): void
    {
        $repository = new InMemoryClinicalDocumentRetentionRepository([
            ['document_id' => 1, 'storage_key' => $this->key('a')->value, 'created_at' => $this->daysAgo(2100)],
            ['document_id' => 2, 'storage_key' => $this->key('b')->value, 'created_at' => $this->daysAgo(2000)],
            ['document_id' => 3, 'storage_key' => $this->key('c')->value, 'created_at' => $this->daysAgo(1900)],
        ]);

        $result = (new ClinicalDocumentRetentionService(
            $repository,
            new InMemoryDocumentStorage(),
            new RetentionPolicy(1825),
        ))->purge('cli:test', 1, 2);

        self::assertSame(2, $result['documents']);
        self::assertTrue($result['remaining']);
        self::assertSame([3], array_column($repository->documents, 'document_id'), 'The newest is still waiting.');
    }

    public function testTheRunNamesTheActorAndTheDocumentCount(): void
    {
        $repository = new InMemoryClinicalDocumentRetentionRepository([
            ['document_id' => 1, 'storage_key' => $this->key()->value, 'created_at' => $this->daysAgo(2000)],
        ]);

        (new ClinicalDocumentRetentionService($repository, new InMemoryDocumentStorage(), new RetentionPolicy(1825)))
            ->purge('cli:retention', 10, 5);

        self::assertCount(1, $repository->runs);
        self::assertSame('cli:retention', $repository->runs[0]['actor']);
        self::assertSame(1, $repository->runs[0]['documents']);
    }

    public function testThePlanCountsWithoutTouchingAnything(): void
    {
        $key = $this->key();
        $storage = new InMemoryDocumentStorage();
        $storage->seed($key, 'bytes');

        $repository = new InMemoryClinicalDocumentRetentionRepository([
            ['document_id' => 1, 'storage_key' => $key->value, 'created_at' => $this->daysAgo(2000)],
        ]);

        $plan = (new ClinicalDocumentRetentionService($repository, $storage, new RetentionPolicy(1825)))->plan();

        self::assertSame(1, $plan['eligible']);
        self::assertSame(1825, $plan['days']);
        self::assertTrue($storage->exists($key), 'plan() must not delete anything.');
        self::assertSame([], $repository->redacted);
    }

    private function daysAgo(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - ($days * self::DAY));
    }

    /**
     * A storage key is exactly 32 lowercase hex characters — it is the value the column stores and the
     * only thing `fromString` accepts, so the fixture has to produce a real one rather than a path.
     */
    private function key(string $digit = 'a'): StorageKey
    {
        return StorageKey::fromString(str_repeat($digit, 32));
    }
}
