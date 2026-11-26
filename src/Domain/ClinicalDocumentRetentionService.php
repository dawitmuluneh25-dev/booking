<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Applies the `uploaded_clinical_documents` class of the retention schedule (D-02: 1,825 days).
 *
 * This is the first sweep in the system that deletes a clinical attachment while the record it was
 * attached to is still live. That is correct under the schedule — documents are five years, referrals are
 * seven — but it is a new kind of act, so the order inside one document is deliberate and copied from the
 * referral sweep for the same reason: **bytes first, then the row.**
 *
 * If the file delete fails, the row is left alone and the sweep throws. The alternative order produces a
 * redacted row whose file is still in private storage with nothing left to find it by: the row no longer
 * names the document, so nobody can enumerate what is still there. A sweep that stops and asks for a human
 * is recoverable; that state is not.
 *
 * The parent referral is never touched. A document reaches this sweep only because it is older than 1,825
 * days while its referral is younger than 2,557 — so the referral is still protected, and redacting it here
 * would destroy a record the schedule says to keep.
 */
final class ClinicalDocumentRetentionService
{
    public function __construct(
        private readonly ClinicalDocumentRetentionRepository $repository,
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
     * @return array{
     *     documents: int, files: int, batches: int, cutoff: string, remaining: bool
     * }
     */
    public function purge(string $actor, int $batchSize = 500, int $maxBatches = 20): array
    {
        $actor = trim($actor);

        if ($actor === '') {
            $actor = 'retention:cli';
        }

        $cutoff = $this->policy->cutoff();
        $cutoffUtc = $cutoff->format('Y-m-d H:i:s');

        $batchSize = max(1, $batchSize);
        $maxBatches = max(1, $maxBatches);

        $documents = 0;
        $files = 0;
        $batches = 0;
        $remaining = false;

        while ($batches < $maxBatches) {
            $batch = $this->repository->eligibleDocuments($cutoffUtc, $batchSize);

            if ($batch === []) {
                $remaining = false;
                break;
            }

            $now = gmdate('Y-m-d H:i:s');

            foreach ($batch as $document) {
                $key = StorageKey::fromString($document['storage_key']);

                if ($this->storage->delete($key)) {
                    $files++;
                }

                $this->repository->redactDocument($document['document_id'], $now);
                $this->repository->markDocumentDeleted($document['document_id'], $now);
                $documents++;
            }

            $batches++;

            if (count($batch) < $batchSize) {
                $remaining = false;
                break;
            }

            $remaining = $batches >= $maxBatches;
        }

        if ($documents > 0) {
            $this->repository->recordRun($cutoff->format('Y-m-d'), $documents, $actor);
        }

        return [
            'documents' => $documents,
            'files' => $files,
            'batches' => $batches,
            'cutoff' => $cutoffUtc,
            'remaining' => $remaining,
        ];
    }
}
