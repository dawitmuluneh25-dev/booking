<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\ContentRepository;
use App\Domain\ContentStatus;
use App\Domain\PublishedContent;

/**
 * In-memory content store, keeping the invariants the MySQL one keeps.
 *
 * The one-live-version rule is enforced here too. A fake that allows two live
 * versions of a page would let a unit test pass against a state the real database
 * refuses, which is the failure mode a test double is supposed to prevent.
 */
final class InMemoryContentRepository implements ContentRepository
{
    /** @var array<int, PublishedContent> */
    private array $records = [];

    private int $nextId = 1;

    public function published(string $contentKey, string $language): ?PublishedContent
    {
        foreach ($this->records as $record) {
            if (
                $record->contentKey === $contentKey
                && $record->language === $language
                && $record->status === ContentStatus::Published
            ) {
                return $record;
            }
        }

        return null;
    }

    public function find(int $id): ?PublishedContent
    {
        return $this->records[$id] ?? null;
    }

    public function findVersion(string $contentKey, string $version, string $language): ?PublishedContent
    {
        foreach ($this->records as $record) {
            if (
                $record->contentKey === $contentKey
                && $record->version === $version
                && $record->language === $language
            ) {
                return $record;
            }
        }

        return null;
    }

    public function all(): array
    {
        $records = array_values($this->records);

        usort(
            $records,
            static fn (PublishedContent $left, PublishedContent $right): int => [$left->contentKey, $right->version]
                <=> [$right->contentKey, $left->version],
        );

        return $records;
    }

    public function servable(string $today): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (PublishedContent $record): bool => $record->isServableOn($today),
        ));
    }

    public function importDraft(PublishedContent $content, string $occurredAtUtc): int
    {
        $existing = $this->findVersion($content->contentKey, $content->version, $content->language);

        if ($existing !== null) {
            return $existing->id;
        }

        $id = $this->nextId;
        $this->nextId++;

        $this->records[$id] = $this->copy($content, [
            'id' => $id,
            'status' => ContentStatus::Draft,
            'createdAtUtc' => $occurredAtUtc,
            'updatedAtUtc' => $occurredAtUtc,
        ]);

        return $id;
    }

    public function reconcileDraft(int $id, PublishedContent $content, string $occurredAtUtc): bool
    {
        $record = $this->records[$id] ?? null;

        if ($record === null || $record->status !== ContentStatus::Draft) {
            return false;
        }

        // The §17.5 fields come from the source; identity, status and the timestamps
        // that record acts stay with the row.
        $this->records[$id] = new PublishedContent(
            id: $record->id,
            contentKey: $record->contentKey,
            audience: $record->audience,
            language: $record->language,
            title: $content->title,
            body: $content->body,
            version: $record->version,
            author: $content->author,
            clinicalReviewer: $content->clinicalReviewer,
            approver: $content->approver,
            approvedOn: $content->approvedOn,
            effectiveOn: $content->effectiveOn,
            reviewOn: $content->reviewOn,
            supersededVersion: $content->supersededVersion,
            translationReviewStatus: $content->translationReviewStatus,
            status: $record->status,
            publishedAtUtc: $record->publishedAtUtc,
            publishedByUserId: $record->publishedByUserId,
            withdrawnAtUtc: $record->withdrawnAtUtc,
            withdrawnByUserId: $record->withdrawnByUserId,
            createdAtUtc: $record->createdAtUtc,
            updatedAtUtc: $occurredAtUtc,
        );

        return true;
    }

    public function publish(int $id, int $actorUserId, string $occurredAtUtc): void
    {
        $record = $this->records[$id] ?? null;

        if ($record === null || $record->status !== ContentStatus::Draft) {
            throw new \RuntimeException('Content #' . $id . ' was not a draft when it was published.');
        }

        if ($this->published($record->contentKey, $record->language) !== null) {
            throw new \PDOException('Duplicate entry for key uq_published_content_one_live');
        }

        $this->records[$id] = $this->copy($record, [
            'status' => ContentStatus::Published,
            'publishedAtUtc' => $occurredAtUtc,
            'publishedByUserId' => $actorUserId,
            'updatedAtUtc' => $occurredAtUtc,
        ]);
    }

    public function withdraw(int $id, int $actorUserId, string $occurredAtUtc): void
    {
        $record = $this->records[$id] ?? null;

        if ($record === null || $record->status !== ContentStatus::Published) {
            throw new \RuntimeException('Content #' . $id . ' was not published when it was withdrawn.');
        }

        $this->records[$id] = $this->copy($record, [
            'status' => ContentStatus::Withdrawn,
            'withdrawnAtUtc' => $occurredAtUtc,
            'withdrawnByUserId' => $actorUserId,
            'updatedAtUtc' => $occurredAtUtc,
        ]);
    }

    /** Seed a record directly, for page and command tests. */
    public function seed(PublishedContent $content): PublishedContent
    {
        $id = $content->id > 0 ? $content->id : $this->nextId;
        $this->nextId = max($this->nextId, $id + 1);

        $this->records[$id] = $this->copy($content, ['id' => $id]);

        return $this->records[$id];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function copy(PublishedContent $content, array $overrides): PublishedContent
    {
        $status = $overrides['status'] ?? $content->status;
        $publishedAtUtc = $overrides['publishedAtUtc'] ?? $content->publishedAtUtc;
        $publishedByUserId = $overrides['publishedByUserId'] ?? $content->publishedByUserId;
        $withdrawnAtUtc = $overrides['withdrawnAtUtc'] ?? $content->withdrawnAtUtc;
        $withdrawnByUserId = $overrides['withdrawnByUserId'] ?? $content->withdrawnByUserId;

        return new PublishedContent(
            id: isset($overrides['id']) && is_int($overrides['id']) ? $overrides['id'] : $content->id,
            contentKey: $content->contentKey,
            audience: $content->audience,
            language: $content->language,
            title: $content->title,
            body: $content->body,
            version: $content->version,
            author: $content->author,
            clinicalReviewer: $content->clinicalReviewer,
            approver: $content->approver,
            approvedOn: $content->approvedOn,
            effectiveOn: $content->effectiveOn,
            reviewOn: $content->reviewOn,
            supersededVersion: $content->supersededVersion,
            translationReviewStatus: $content->translationReviewStatus,
            status: $status instanceof ContentStatus ? $status : $content->status,
            publishedAtUtc: is_string($publishedAtUtc) ? $publishedAtUtc : null,
            publishedByUserId: is_int($publishedByUserId) ? $publishedByUserId : null,
            withdrawnAtUtc: is_string($withdrawnAtUtc) ? $withdrawnAtUtc : null,
            withdrawnByUserId: is_int($withdrawnByUserId) ? $withdrawnByUserId : null,
            createdAtUtc: isset($overrides['createdAtUtc']) && is_string($overrides['createdAtUtc'])
                ? $overrides['createdAtUtc']
                : $content->createdAtUtc,
            updatedAtUtc: isset($overrides['updatedAtUtc']) && is_string($overrides['updatedAtUtc'])
                ? $overrides['updatedAtUtc']
                : $content->updatedAtUtc,
        );
    }
}
