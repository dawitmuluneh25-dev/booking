<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ContentSource;
use App\Domain\ContentStatus;
use App\Domain\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * The mapping between the service-page source file and a draft record.
 *
 * This exists because the mapping was once a block of code inside the console
 * command, and it hard-coded `clinicalReviewer` to null. Nothing failed: the field
 * is allowed to be null, so a supplied reviewer's name silently never reached the
 * record and the page simply reported itself as missing a reviewer forever. The
 * first test below is the one that would have caught it.
 */
final class ContentSourceTest extends TestCase
{
    public function testEveryFieldTheSourceCarriesReachesTheDraft(): void
    {
        $draft = (new ContentSource())->toDraft($this->entry(), '2026-11-13 10:00:00');

        self::assertSame('Dawit Muluneh', $draft->clinicalReviewer, 'The reviewer did not survive the mapping.');
        self::assertSame('Dawit Muluneh', $draft->approver);
        self::assertSame('2026-11-13', $draft->approvedOn);
        self::assertSame('2026-11-13', $draft->effectiveOn);
        self::assertSame('2027-11-13', $draft->reviewOn);
        self::assertSame('source', $draft->translationReviewStatus);
        self::assertSame('HR-IR-CLIN-R1-001 clinical package', $draft->author);
        self::assertSame('1.0', $draft->version);
        self::assertSame('en', $draft->language);
        self::assertSame('patient', $draft->audience);
        self::assertSame(ContentStatus::Draft, $draft->status);
    }

    public function testAnEmptyStringIsAbsenceRatherThanAName(): void
    {
        // "" as a reviewer satisfies a NOT NULL check while telling nobody who
        // reviewed the page, so it has to arrive as null.
        $draft = (new ContentSource())->toDraft(
            $this->entry(['clinical_reviewer' => '   ', 'approver' => '']),
            '2026-11-13 10:00:00',
        );

        self::assertNull($draft->clinicalReviewer);
        self::assertNull($draft->approver);
    }

    public function testTheSectionsBecomeJsonThatDecodesBackToTheSameShape(): void
    {
        $draft = (new ContentSource())->toDraft($this->entry(), '2026-11-13 10:00:00');

        $sections = $draft->sections();

        self::assertCount(2, $sections);
        self::assertSame('What it is', $sections[0]['heading']);
        self::assertSame(['A sampling procedure.'], $sections[0]['paragraphs']);
        self::assertSame([['question' => 'Why?', 'answer' => 'Because.']], $sections[1]['faq']);
    }

    public function testAnEntryWithNoSectionsIsRefusedRatherThanPublishedEmpty(): void
    {
        $this->expectException(ValidationException::class);

        (new ContentSource())->toDraft($this->entry(['sections' => []]), '2026-11-13 10:00:00');
    }

    public function testAnEntryMissingItsKeyOrTitleIsRefused(): void
    {
        foreach (['key', 'title', 'author'] as $field) {
            try {
                (new ContentSource())->toDraft($this->entry([$field => '']), '2026-11-13 10:00:00');
                self::fail('An entry with no ' . $field . ' was accepted.');
            } catch (ValidationException $exception) {
                self::assertStringContainsString($field, $exception->errors['source'] ?? '');
            }
        }
    }

    /**
     * The real source file, checked as data rather than as a mapping.
     *
     * Four pages, each §17.5-complete, with a review date that can follow its
     * approval — the exact shape the package's own rules require.
     */
    public function testTheCommittedSourceIsFourCompletePages(): void
    {
        $pages = require dirname(__DIR__, 2) . '/database/content/service-pages.php';

        self::assertIsArray($pages);
        self::assertCount(4, $pages);

        $keys = [];

        foreach ($pages as $page) {
            self::assertIsArray($page);

            $draft = (new ContentSource())->toDraft($page, '2026-11-13 10:00:00');

            self::assertSame([], $draft->missingForPublication(), $draft->contentKey . ' is incomplete.');
            self::assertNotNull($draft->reviewOn);
            self::assertNotNull($draft->approvedOn);
            self::assertTrue(
                $draft->reviewOn >= $draft->approvedOn,
                'A review date precedes the approval it reviews.',
            );

            $keys[] = $draft->contentKey;
        }

        self::assertSame($keys, array_unique($keys), 'Two pages share a content key.');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function entry(array $overrides = []): array
    {
        return $overrides + [
            'key' => 'service.fnac',
            'title' => 'Image-Guided Fine Needle Aspiration Cytology (FNAC)',
            'author' => 'HR-IR-CLIN-R1-001 clinical package',
            'approver' => 'Dawit Muluneh',
            'approved_on' => '2026-11-13',
            'clinical_reviewer' => 'Dawit Muluneh',
            'effective_on' => '2026-11-13',
            'review_on' => '2027-11-13',
            'translation_review_status' => 'source',
            'sections' => [
                ['heading' => 'What it is', 'paragraphs' => ['A sampling procedure.']],
                ['heading' => 'Questions', 'faq' => [['question' => 'Why?', 'answer' => 'Because.']]],
            ],
        ];
    }
}
