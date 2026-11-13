<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\ContentStatus;
use App\Domain\PublishedContent;

/**
 * Builds content records for tests.
 *
 * The defaults are a *complete* §17.5 record, so a test that wants to prove a rule
 * about an incomplete one has to say which field it is withholding. That is the
 * right way round: a default that was already missing a date would make every
 * publish test pass for the wrong reason.
 */
final class ContentFactory
{
    /** @param array<string, mixed> $overrides */
    public static function content(string $contentKey = 'service.fnac', array $overrides = []): PublishedContent
    {
        $defaults = [
            'id' => 1,
            'contentKey' => $contentKey,
            'audience' => 'patient',
            'language' => 'en',
            'title' => 'Image-Guided Fine Needle Aspiration Cytology (FNAC)',
            'body' => json_encode(
                [['heading' => 'What it is', 'paragraphs' => ['A sampling procedure.']]],
                JSON_THROW_ON_ERROR,
            ),
            'version' => '1.0',
            'author' => 'HR-IR-CLIN-R1-001 clinical package',
            'clinicalReviewer' => 'Dr Reviewer',
            'approver' => 'Dawit Muluneh',
            'approvedOn' => '2026-11-13',
            'effectiveOn' => '2026-11-13',
            'reviewOn' => '2027-11-13',
            'supersededVersion' => null,
            'translationReviewStatus' => 'source',
            'status' => ContentStatus::Draft,
            'publishedAtUtc' => null,
            'publishedByUserId' => null,
            'withdrawnAtUtc' => null,
            'withdrawnByUserId' => null,
            'createdAtUtc' => '2026-11-13 08:00:00',
            'updatedAtUtc' => '2026-11-13 08:00:00',
        ];

        $merged = $overrides + $defaults;

        return new PublishedContent(
            id: is_int($merged['id']) ? $merged['id'] : 0,
            contentKey: is_string($merged['contentKey']) ? $merged['contentKey'] : $contentKey,
            audience: is_string($merged['audience']) ? $merged['audience'] : 'patient',
            language: is_string($merged['language']) ? $merged['language'] : 'en',
            title: is_string($merged['title']) ? $merged['title'] : '',
            body: is_string($merged['body']) ? $merged['body'] : '[]',
            version: is_string($merged['version']) ? $merged['version'] : '1.0',
            author: is_string($merged['author']) ? $merged['author'] : '',
            clinicalReviewer: is_string($merged['clinicalReviewer']) ? $merged['clinicalReviewer'] : null,
            approver: is_string($merged['approver']) ? $merged['approver'] : null,
            approvedOn: is_string($merged['approvedOn']) ? $merged['approvedOn'] : null,
            effectiveOn: is_string($merged['effectiveOn']) ? $merged['effectiveOn'] : null,
            reviewOn: is_string($merged['reviewOn']) ? $merged['reviewOn'] : null,
            supersededVersion: is_string($merged['supersededVersion']) ? $merged['supersededVersion'] : null,
            translationReviewStatus: is_string($merged['translationReviewStatus'])
                ? $merged['translationReviewStatus']
                : null,
            status: $merged['status'] instanceof ContentStatus ? $merged['status'] : ContentStatus::Draft,
            publishedAtUtc: is_string($merged['publishedAtUtc']) ? $merged['publishedAtUtc'] : null,
            publishedByUserId: is_int($merged['publishedByUserId']) ? $merged['publishedByUserId'] : null,
            withdrawnAtUtc: is_string($merged['withdrawnAtUtc']) ? $merged['withdrawnAtUtc'] : null,
            withdrawnByUserId: is_int($merged['withdrawnByUserId']) ? $merged['withdrawnByUserId'] : null,
            createdAtUtc: is_string($merged['createdAtUtc']) ? $merged['createdAtUtc'] : '2026-11-13 08:00:00',
            updatedAtUtc: is_string($merged['updatedAtUtc']) ? $merged['updatedAtUtc'] : '2026-11-13 08:00:00',
        );
    }

    /**
     * The same record, already live.
     *
     * @param array<string, mixed> $overrides
     */
    public static function published(string $contentKey = 'service.fnac', array $overrides = []): PublishedContent
    {
        return self::content($contentKey, $overrides + [
            'status' => ContentStatus::Published,
            'publishedAtUtc' => '2026-11-13 09:00:00',
            'publishedByUserId' => 1,
        ]);
    }
}
