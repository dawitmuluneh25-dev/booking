<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ReferralRetentionService;
use App\Domain\RetentionPolicy;
use App\Domain\StorageKey;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDocumentStorage;
use Tests\Support\InMemoryReferralRetentionRepository;

/**
 * Phase 4 criterion 4: the retention run redacts document metadata with the
 * referral, and the files are removed on the same schedule.
 *
 * "Same schedule" is structural here - one policy, one cutoff, one sweep - so the
 * test asserts that a referral that is old enough takes its files with it, and
 * that a referral that is not is left completely alone.
 */
final class ReferralRetentionServiceTest extends TestCase
{
    private InMemoryReferralRetentionRepository $repository;

    private InMemoryDocumentStorage $storage;

    private ReferralRetentionService $service;

    protected function setUp(): void
    {
        $this->repository = new InMemoryReferralRetentionRepository();
        $this->storage = new InMemoryDocumentStorage();
        $this->service = new ReferralRetentionService(
            $this->repository,
            $this->storage,
            new RetentionPolicy(730),
        );
    }

    public function testAnEligibleReferralsFilesAndMetadataGoTogether(): void
    {
        $first = StorageKey::generate();
        $second = StorageKey::generate();
        $this->storage->seed($first, 'scan bytes');
        $this->storage->seed($second, 'letter bytes');

        $this->repository->seedReferral(11, true, [
            ['document_id' => 101, 'storage_key' => $first->value],
            ['document_id' => 102, 'storage_key' => $second->value],
        ]);

        $result = $this->service->purge('phpunit');

        self::assertSame(1, $result['referrals']);
        self::assertSame(2, $result['documents']);
        self::assertSame([11], $this->repository->redactedReferrals());
        self::assertSame([101, 102], $this->repository->redactedDocuments());
        self::assertSame([101, 102], $this->repository->deletedDocuments());
        self::assertSame(0, $this->storage->count());
    }

    public function testAReferralInsideTheWindowIsUntouched(): void
    {
        $key = StorageKey::generate();
        $this->storage->seed($key, 'still needed');

        $this->repository->seedReferral(12, false, [
            ['document_id' => 103, 'storage_key' => $key->value],
        ]);

        $result = $this->service->purge('phpunit');

        self::assertSame(0, $result['referrals']);
        self::assertSame([], $this->repository->redactedReferrals());
        self::assertSame(1, $this->storage->count());
    }

    public function testAFileThatIsAlreadyGoneIsStillClosedOnTheRecord(): void
    {
        $key = StorageKey::generate();

        $this->repository->seedReferral(13, true, [
            ['document_id' => 104, 'storage_key' => $key->value],
        ]);

        $result = $this->service->purge('phpunit');

        self::assertSame(1, $result['referrals']);
        self::assertSame(0, $result['documents']);
        self::assertSame([104], $this->repository->deletedDocuments());
    }

    public function testTheSweepIsRecordedAndIdempotent(): void
    {
        $key = StorageKey::generate();
        $this->storage->seed($key, 'bytes');
        $this->repository->seedReferral(14, true, [
            ['document_id' => 105, 'storage_key' => $key->value],
        ]);

        $this->service->purge('cli:retention');

        $runs = $this->repository->runs();
        self::assertCount(1, $runs);
        self::assertSame(1, $runs[0]['referrals']);
        self::assertSame(1, $runs[0]['documents']);
        self::assertSame('cli:retention', $runs[0]['actor']);

        $second = $this->service->purge('cli:retention');

        self::assertSame(0, $second['referrals']);
        self::assertCount(1, $this->repository->runs());
    }

    public function testThePlanCountsWhatWouldBeRedacted(): void
    {
        $this->repository->seedReferral(15, true);
        $this->repository->seedReferral(16, true);
        $this->repository->seedReferral(17, false);

        $plan = $this->service->plan();

        self::assertSame(2, $plan['eligible']);
        self::assertSame(730, $plan['days']);
        self::assertNotSame('', $plan['cutoff']);
    }
}
