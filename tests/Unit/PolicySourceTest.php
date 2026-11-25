<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ContentSource;
use App\Domain\ContentStatus;
use PHPUnit\Framework\TestCase;

/**
 * `database/content/policy-pages.php` as data, on its way to a row.
 *
 * `PolicyWordingTest` pins the words in the file. This pins what happens to them next: `content:sync`
 * is run on a host where a failure is expensive to diagnose, so the file has to convert cleanly
 * before it gets there. The conversion is where a page can lose a `bullets` list, arrive with a
 * blank title, or — the failure this is really for — claim an approval it does not have.
 *
 * **These assertions were inverted on 25 November 2026, and that is the point.** Until then the
 * three pages were drafts that had to stay unpublishable by construction, and the assertion was not
 * "they are complete" but "they are incomplete in exactly the ways the decision documents leave open".
 * D-01's approval arrived — Legal/Data Protection, named and dated — so the assertion is now that each
 * page carries exactly that approval and nothing invented alongside it. The tests did not get weaker;
 * they got a different subject: an approval that is *present but wrong* (a different name, a guessed
 * date, a clinical reviewer on a policy page) is the failure mode now.
 */
final class PolicySourceTest extends TestCase
{
    /**
     * The approval D-01 was waiting for, as given on 25 November 2026.
     *
     * Not the clinical reviewer: a policy notice is approved by Legal/Data Protection, not by a
     * clinician, and `PublishedContent::missingForPublication()` does not ask a `policy.` page for one.
     */
    private const APPROVER = 'Gemechisa Dalu';

    private const APPROVED_ON = '2026-11-25';

    private const EFFECTIVE_ON = '2026-12-18';

    /** @return list<array<string, mixed>> */
    private static function pages(): array
    {
        $pages = require dirname(__DIR__, 2) . '/database/content/policy-pages.php';

        if (!is_array($pages)) {
            self::fail('policy-pages.php did not return a list of pages.');
        }

        $out = [];

        foreach ($pages as $page) {
            if (!is_array($page)) {
                self::fail('policy-pages.php returned an entry that is not a page.');
            }

            /** @var array<string, mixed> $page */
            $out[] = $page;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return list<array<string, mixed>>
     */
    private static function sectionsOf(array $page): array
    {
        $sections = $page['sections'] ?? null;

        if (!is_array($sections)) {
            self::fail('A page in policy-pages.php has no sections.');
        }

        $out = [];

        foreach ($sections as $section) {
            if (!is_array($section)) {
                self::fail('A section in policy-pages.php is not a block.');
            }

            /** @var array<string, mixed> $section */
            $out[] = $section;
        }

        return $out;
    }

    /** @param array<string, mixed> $page */
    private static function header(array $page, string $field): string
    {
        $value = $page[$field] ?? null;

        if (!is_string($value)) {
            self::fail('A page in policy-pages.php has no string "' . $field . '".');
        }

        return $value;
    }

    /**
     * A list from a section, as a list, whatever the file wrote there.
     *
     * @param array<string, mixed> $section
     *
     * @return list<string>
     */
    private static function strings(array $section, string $field): array
    {
        $value = $section[$field] ?? null;

        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            self::fail('Section "' . self::header($section, 'heading') . '" has a non-list "' . $field . '".');
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item)));
    }

    /**
     * FAQ entries, as a list of question/answer pairs.
     *
     * @param array<string, mixed> $section
     *
     * @return list<array{question: string, answer: string}>
     */
    private static function faq(array $section): array
    {
        $value = $section['faq'] ?? null;

        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            self::fail('Section "' . self::header($section, 'heading') . '" has a non-list "faq".');
        }

        $out = [];

        foreach ($value as $entry) {
            if (!is_array($entry)) {
                self::fail('An FAQ entry is not a pair.');
            }

            /** @var array<string, mixed> $entry */
            $question = $entry['question'] ?? null;
            $answer = $entry['answer'] ?? null;

            if (!is_string($question) || !is_string($answer)) {
                self::fail('An FAQ entry is missing its question or answer.');
            }

            $out[] = ['question' => $question, 'answer' => $answer];
        }

        return $out;
    }

    public function testTheFileHoldsTheThreePolicyPages(): void
    {
        $keys = [];

        foreach (self::pages() as $page) {
            $keys[] = self::header($page, 'key');
        }

        self::assertSame(['policy.privacy', 'policy.booking', 'policy.referral'], $keys);
    }

    public function testEveryPageConvertsToADraftWithoutLosingASection(): void
    {
        $source = new ContentSource();

        foreach (self::pages() as $page) {
            $draft = $source->toDraft($page, '2026-11-22 20:00:00');

            self::assertSame(
                ContentStatus::Draft,
                $draft->status,
                self::header($page, 'key') . ' did not arrive as a draft.',
            );
            self::assertSame('en', $draft->language);
            self::assertSame('patient', $draft->audience);
            self::assertSame('source', $draft->translationReviewStatus);

            // `sections()` normalises every block to heading + paragraphs + bullets + faq, filling
            // empty lists for the two the source did not use. The comparison is against that shape,
            // not the file's, so a section that used bullets is not reported as having gained an
            // empty paragraph list — and a section that lost its bullets still fails, because the
            // list that was there is gone.
            $expected = [];

            foreach (self::sectionsOf($page) as $section) {
                $expected[] = [
                    'heading' => self::header($section, 'heading'),
                    'paragraphs' => self::strings($section, 'paragraphs'),
                    'bullets' => self::strings($section, 'bullets'),
                    'faq' => self::faq($section),
                ];
            }

            self::assertSame(
                $expected,
                $draft->sections(),
                self::header($page, 'key') . ' lost or reordered a section.',
            );
        }
    }

    /**
     * The approval is present, and it is the one that was given.
     *
     * An approval that is present but wrong is worse than an absent one: the absent one stops the
     * gate, and the wrong one opens it. So the name, the date and the effective date are each pinned.
     */
    public function testEveryPageCarriesTheApprovalItWasGivenAndNothingElse(): void
    {
        $source = new ContentSource();

        foreach (self::pages() as $page) {
            $draft = $source->toDraft($page, '2026-11-25 20:00:00');
            $key = self::header($page, 'key');

            self::assertNull($draft->clinicalReviewer, $key . ' names a clinical reviewer.');
            self::assertSame(self::APPROVER, $draft->approver, $key . ' names the wrong approver.');
            self::assertSame(self::APPROVED_ON, $draft->approvedOn, $key . ' carries the wrong approval date.');
            self::assertSame(self::EFFECTIVE_ON, $draft->effectiveOn, $key . ' carries the wrong effective date.');

            // D-01 fixed the review date and only the effective date was re-set by the approver.
            self::assertSame('2028-01-12', $draft->reviewOn, $key . ' does not carry D-01\'s review date.');
        }
    }

    /**
     * Nothing is missing any more: the approval that D-01 made a precondition is on the record.
     *
     * This is the test that used to prove the pages were unpublishable. It now proves they are not —
     * and if a future edit drops the approver, it fails with the field name rather than with a
     * publication that quietly does not happen.
     */
    public function testEachPageIsComplete(): void
    {
        $source = new ContentSource();

        foreach (self::pages() as $page) {
            $missing = $source->toDraft($page, '2026-11-25 20:00:00')->missingForPublication();

            self::assertSame(
                [],
                $missing,
                self::header($page, 'key') . ' is incomplete: ' . implode(', ', $missing),
            );
        }
    }

    /**
     * Two pages sharing a key would make `content:sync` overwrite one with the other, and the
     * version it did not write would look like it had never been imported.
     */
    public function testNoTwoPagesShareAKey(): void
    {
        $keys = array_map(static fn (array $page): string => self::header($page, 'key'), self::pages());

        self::assertSame($keys, array_values(array_unique($keys)));
    }
}
