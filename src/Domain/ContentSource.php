<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Turns one entry of the service-page source file into a draft record (§17.5).
 *
 * This is a class rather than a few lines inside the console command because it
 * already went wrong there. The command built its drafts with `clinicalReviewer`
 * hard-coded to null, so the reviewer's name never reached the record no matter
 * what the source said — and nothing failed, because the field is allowed to be
 * null. An authorisation to fill a field is only as good as the mapping that
 * carries it, so the mapping is now somewhere a test can reach.
 *
 * It validates rather than trusts: the source is a PHP file written by hand, and a
 * typo in a heading or a missing key should be a message about the entry rather
 * than a page that renders half of itself.
 */
final class ContentSource
{
    /** The package this release publishes is version 1.0. */
    public const VERSION = '1.0';

    /** §17's copy is English, and English is its source language. */
    public const LANGUAGE = 'en';

    /** §6.1: this copy is written for a patient. */
    public const AUDIENCE = 'patient';

    private const REQUIRED_KEYS = ['key', 'title', 'author'];

    /**
     * @param array<string, mixed> $page one entry of database/content/service-pages.php
     *
     * @throws ValidationException the entry cannot become a page
     */
    public function toDraft(array $page, string $nowUtc): PublishedContent
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (!is_string($page[$key] ?? null) || trim((string) $page[$key]) === '') {
                throw new ValidationException([
                    'source' => 'A content entry is missing its "' . $key . '".',
                ]);
            }
        }

        $sections = $page['sections'] ?? null;

        if (!is_array($sections) || $sections === []) {
            throw new ValidationException([
                'source' => 'The entry "' . $page['key'] . '" has no sections to show.',
            ]);
        }

        return new PublishedContent(
            id: 0,
            contentKey: (string) $page['key'],
            audience: self::AUDIENCE,
            language: self::LANGUAGE,
            title: (string) $page['title'],
            body: json_encode($sections, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            version: self::VERSION,
            author: (string) $page['author'],
            clinicalReviewer: self::nullableString($page['clinical_reviewer'] ?? null),
            approver: self::nullableString($page['approver'] ?? null),
            approvedOn: self::nullableString($page['approved_on'] ?? null),
            effectiveOn: self::nullableString($page['effective_on'] ?? null),
            reviewOn: self::nullableString($page['review_on'] ?? null),
            supersededVersion: self::nullableString($page['superseded_version'] ?? null),
            translationReviewStatus: self::nullableString($page['translation_review_status'] ?? null),
            status: ContentStatus::Draft,
            publishedAtUtc: null,
            publishedByUserId: null,
            withdrawnAtUtc: null,
            withdrawnByUserId: null,
            createdAtUtc: $nowUtc,
            updatedAtUtc: $nowUtc,
        );
    }

    /**
     * Absent is absent.
     *
     * An empty string in the source means "not supplied"; it must not become a
     * value, because "" as a reviewer name is a name that satisfies a NOT NULL
     * check while telling nobody who reviewed the page.
     */
    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
