<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\ContentRepository;
use App\Domain\PublishedContent;
use PDO;

/** MySQL implementation of patient-facing content (§17.5). */
final class PdoContentRepository implements ContentRepository
{
    private const COLUMNS = 'id, content_key, audience, language, title, body, version, author,
        clinical_reviewer, approver, approved_on, effective_on, review_on, superseded_version,
        translation_review_status, status, published_at, published_by_user_id, withdrawn_at,
        withdrawn_by_user_id, created_at, updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function published(string $contentKey, string $language): ?PublishedContent
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM published_content
              WHERE content_key = :content_key AND language = :language AND status = \'published\'
              LIMIT 1'
        );
        $statement->execute(['content_key' => $contentKey, 'language' => $language]);

        return $this->one($statement);
    }

    public function find(int $id): ?PublishedContent
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM published_content WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        return $this->one($statement);
    }

    public function findVersion(string $contentKey, string $version, string $language): ?PublishedContent
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM published_content
              WHERE content_key = :content_key AND version = :version AND language = :language
              LIMIT 1'
        );
        $statement->execute([
            'content_key' => $contentKey,
            'version' => $version,
            'language' => $language,
        ]);

        return $this->one($statement);
    }

    public function all(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM published_content
              ORDER BY content_key ASC, created_at DESC, id DESC'
        );
        $statement->execute();

        return $this->hydrate($statement);
    }

    public function servable(string $today): array
    {
        // A page is expired once its review date has passed, so "servable" means the
        // review date is today or later. Written into the WHERE clause rather than
        // filtered in PHP: a page that must not be shown should not be assembled at all.
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM published_content
              WHERE status = \'published\'
                AND (effective_on IS NULL OR effective_on <= :effective_by)
                AND (review_on IS NULL OR review_on >= :review_from)
              ORDER BY content_key ASC'
        );
        $statement->execute(['effective_by' => $today, 'review_from' => $today]);

        return $this->hydrate($statement);
    }

    public function importDraft(PublishedContent $content, string $occurredAtUtc): int
    {
        $existing = $this->findVersion($content->contentKey, $content->version, $content->language);

        if ($existing !== null) {
            return $existing->id;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO published_content (
                content_key, audience, language, title, body, version, author,
                clinical_reviewer, approver, approved_on, effective_on, review_on,
                superseded_version, translation_review_status, status, created_at, updated_at
             ) VALUES (
                :content_key, :audience, :language, :title, :body, :version, :author,
                :clinical_reviewer, :approver, :approved_on, :effective_on, :review_on,
                :superseded_version, :translation_review_status, \'draft\', :created_at, :updated_at
             )'
        );
        $insert->execute([
            'content_key' => $content->contentKey,
            'audience' => $content->audience,
            'language' => $content->language,
            'title' => $content->title,
            'body' => $content->body,
            'version' => $content->version,
            'author' => $content->author,
            'clinical_reviewer' => $content->clinicalReviewer,
            'approver' => $content->approver,
            'approved_on' => $content->approvedOn,
            'effective_on' => $content->effectiveOn,
            'review_on' => $content->reviewOn,
            'superseded_version' => $content->supersededVersion,
            'translation_review_status' => $content->translationReviewStatus,
            'created_at' => $occurredAtUtc,
            'updated_at' => $occurredAtUtc,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function publish(int $id, int $actorUserId, string $occurredAtUtc): void
    {
        // `status = 'draft'` in the WHERE clause is the concurrency guard: two people
        // pressing publish at once must not both succeed and overwrite each other's
        // attribution. The loser updates nothing and is reported as a conflict.
        // Named placeholders cannot be reused with emulated prepares off, so the
        // same timestamp is bound twice under two names.
        $statement = $this->pdo->prepare(
            'UPDATE published_content
                SET status = \'published\', published_at = :published_at, published_by_user_id = :by,
                    updated_at = :updated_at
              WHERE id = :id AND status = \'draft\''
        );
        $statement->execute([
            'published_at' => $occurredAtUtc,
            'by' => $actorUserId,
            'updated_at' => $occurredAtUtc,
            'id' => $id,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Content #' . $id . ' was not a draft when it was published.');
        }
    }

    public function withdraw(int $id, int $actorUserId, string $occurredAtUtc): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE published_content
                SET status = \'withdrawn\', withdrawn_at = :withdrawn_at, withdrawn_by_user_id = :by,
                    updated_at = :updated_at
              WHERE id = :id AND status = \'published\''
        );
        $statement->execute([
            'withdrawn_at' => $occurredAtUtc,
            'by' => $actorUserId,
            'updated_at' => $occurredAtUtc,
            'id' => $id,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Content #' . $id . ' was not published when it was withdrawn.');
        }
    }

    private function one(\PDOStatement $statement): ?PublishedContent
    {
        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : PublishedContent::fromRow($row);
    }

    /** @return list<PublishedContent> */
    private function hydrate(\PDOStatement $statement): array
    {
        $records = [];

        while (($row = $statement->fetch()) !== false) {
            /** @var array<string, mixed> $row */
            $records[] = PublishedContent::fromRow($row);
        }

        return $records;
    }
}
