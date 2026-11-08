<?php

declare(strict_types=1);

namespace App\Domain;

use Throwable;

/**
 * Applies the retention policy to referrals and their documents.
 *
 * Runs against the same `RetentionPolicy` and the same cutoff as the booking
 * sweep, because "same schedule" is the requirement (Phase 4 criterion 4): a
 * referral whose identity has been redacted must not keep a referral letter on
 * disk that still carries the patient's name.
 *
 * Order within one referral is deliberate: delete the bytes, mark the row, then
 * redact the parent. If file removal fails the sweep stops with that referral
 * half-treated - documents intact, referral intact - rather than producing a
 * redacted record whose file is still lying in private storage with no way to
 * find it again.
 */
final class ReferralRetentionService
{
    public function __construct(
        private readonly ReferralRetentionRepository $repository,
        private readonly DocumentStorage $storage,
        private readonly RetentionPolicy $policy,
    ) {
    }

    /** @return array{eligible: int, cutoff: string, days: int} */
    public function plan(): array
    {
        $cutoff = $this->policy->cutoff();

        return [
            'eligible' => $this->repository->eligibleCount($cutoff->format('Y-m-d H:i:s')),
            'cutoff' => $cutoff->format('Y-m-d H:i:s'),
            'days' => $this->policy->days,
        ];
    }

    /**
     * @return array{referrals: int, documents: int, batches: int, cutoff: string, remaining: bool}
     */
    public function purge(string $actor, int $batchSize = 500, int $maxBatches = 20): array
    {
        $actor = trim($actor);
        if ($actor === '') {
            $actor = 'retention:cli';
        }

        $cutoff = $this->policy->cutoff();
        $cutoffUtc = $cutoff->format('Y-m-d H:i:s');
        $now = gmdate('Y-m-d H:i:s');

        $batchSize = max(1, $batchSize);
        $maxBatches = max(1, $maxBatches);

        $referrals = 0;
        $documents = 0;
        $batches = 0;
        $remaining = false;

        while ($batches < $maxBatches) {
            $ids = $this->repository->eligibleIds($cutoffUtc, $batchSize);

            if ($ids === []) {
                $remaining = false;
                break;
            }

            foreach ($ids as $referralId) {
                $documents += $this->redactOne($referralId, $now);
                $referrals++;
            }

            $batches++;

            if (count($ids) < $batchSize) {
                $remaining = false;
                break;
            }

            $remaining = $batches >= $maxBatches;
        }

        if ($referrals > 0) {
            $this->repository->recordRun($cutoff->format('Y-m-d'), $referrals, $documents, $actor);
        }

        return [
            'referrals' => $referrals,
            'documents' => $documents,
            'batches' => $batches,
            'cutoff' => $cutoffUtc,
            'remaining' => $remaining,
        ];
    }

    /** Removes one referral's files and identity, and returns how many files went. */
    private function redactOne(int $referralId, string $occurredAtUtc): int
    {
        $removed = 0;

        foreach ($this->repository->documentsFor($referralId) as $document) {
            try {
                $key = StorageKey::fromString($document['storage_key']);
            } catch (Throwable $exception) {
                // A key that is not a key means the row was tampered with. Stop:
                // deleting a path derived from corrupt data is worse than a sweep
                // that halts and asks for a human.
                throw $exception;
            }

            if ($this->storage->delete($key)) {
                $removed++;
            }

            $this->repository->redactDocument($document['document_id'], $occurredAtUtc);
            $this->repository->markDocumentDeleted($document['document_id'], $occurredAtUtc);
        }

        $this->repository->redactReferral($referralId, $occurredAtUtc);

        return $removed;
    }
}
