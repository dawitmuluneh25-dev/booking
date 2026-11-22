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
 * The three pages are drafts that must stay unpublishable by construction, so the assertion is not
 * "they are complete" but "they are incomplete in exactly the six ways the decision documents leave
 * open and no others". A file with a broken section changes that list, because `toDraft()` refuses
 * an entry with no sections rather than importing an empty page.
 */
final class PolicySourceTest extends TestCase
{
    /**
     * What a policy page still owes, after D-01.
     *
     * Not the clinical reviewer — a policy notice is approved by Legal/Data Protection, not by a
     * clinician (D-01), and `PublishedContent::missingForPublication()` no longer asks a `policy.`
     * page for one. Not the dates either: D-01 fixed them at 12 January 2027 and 12 January 2028.
     * What is left is the one thing the record does not supply — the approval itself.
     */
    private const PROVENANCE = [
        'approver',
        'approval date',
    ];

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
     * The one failure that would be invisible until publication: a file that carries an approver
     * nobody supplied would make the publish gate pass on invented provenance.
     */
    public function testNoPageClaimsAnApprovalItWasNotGiven(): void
    {
        $source = new ContentSource();

        foreach (self::pages() as $page) {
            $draft = $source->toDraft($page, '2026-11-22 20:00:00');
            $key = self::header($page, 'key');

            self::assertNull($draft->clinicalReviewer, $key . ' names a clinical reviewer.');
            self::assertNull($draft->approver, $key . ' names an approver.');
            self::assertNull($draft->approvedOn, $key . ' carries an approval date.');

            // D-01 fixed these two, and fixed them for the set. A page with a different pair has
            // been edited by hand, which is how three pages start disagreeing about when they took
            // effect.
            self::assertSame('2027-01-12', $draft->effectiveOn, $key . ' does not carry D-01\'s effective date.');
            self::assertSame('2028-01-12', $draft->reviewOn, $key . ' does not carry D-01\'s review date.');
        }
    }

    public function testTheOnlyThingEachPageIsMissingIsProvenance(): void
    {
        $source = new ContentSource();

        foreach (self::pages() as $page) {
            $missing = $source->toDraft($page, '2026-11-22 20:00:00')->missingForPublication();

            self::assertSame(
                self::PROVENANCE,
                $missing,
                self::header($page, 'key') . ' is incomplete in a way the decision documents do not '
                . 'account for. A missing title or section means the file is wrong, not that a '
                . 'signature is owed.',
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
