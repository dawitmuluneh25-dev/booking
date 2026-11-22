<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ContentSource;
use PHPUnit\Framework\TestCase;

/**
 * The restricted intussusception page, as `database/content/service-pages.php` now holds it.
 *
 * HR-ENG-DEC-R1-003 D-03 decided that hydrostatic reduction stays an urgent, hospital-based
 * coordination pathway, that it is not a publicly confirmed service, and that the reported
 * paediatric/receiving-hospital approval of 18 November 2026 is not authenticated. The page was
 * nonetheless live on staging, describing in the present tense how coordination runs — a public
 * availability claim written before the decision was made.
 *
 * Four things are pinned here, and they are pinned rather than remembered because three of them
 * are the kind of sentence that gets "tidied" by somebody editing copy:
 *
 *   1. The words are the record's approved paragraph, character for character. The record
 *      authorises exactly one paragraph for this page; paraphrase is not authorised.
 *   2. Nothing else is on the page. A second paragraph would be clinical copy nobody approved.
 *   3. The page cannot be published. D-03 makes publication conditional on a sign-off that is not
 *      recorded, and the honest way to express "not yet" in this schema is an absent effective
 *      date, which `missingForPublication()` names and the database CHECK refuses.
 *   4. No availability claim survives. "Can be booked", "available", "same day" are the words the
 *      decision forbids, and the emergency-safety sentences are the ones it requires.
 */
final class IntussusceptionCopyTest extends TestCase
{
    /**
     * D-03's approved public-facing wording, verbatim (HR-ENG-DEC-R1-003, line 95).
     *
     * If this and the source file ever disagree, one of them has been edited and the other has
     * not. The test does not say which is right; it says they are not the same document any more.
     */
    private const APPROVED = 'Hydrostatic reduction of intussusception is an urgent, hospital-based '
        . 'procedure requiring assessment and coordination with the receiving clinical team, including '
        . 'appropriate paediatric and surgical involvement. Submitting an online request does not confirm '
        . 'clinical acceptance, suitability, or procedure availability. If a child may have '
        . 'intussusception or has concerning symptoms, seek immediate assessment at a hospital or '
        . 'emergency department. Do not wait for an online response.';

    /**
     * Phrases that assert a service is available, bookable or running.
     *
     * "availability" is not on this list on purpose: the approved paragraph uses the word to deny
     * it ("does not confirm … procedure availability"), and a check that cannot tell a denial from
     * a claim would fail the corrected page.
     */
    private const UNAVAILABILITY_CLAIMS = [
        'book',
        'appointment',
        'schedule',
        'walk-in',
        'same-day',
        '24/7',
        'guarantee',
        'we offer',
        'we provide',
        'available at',
        'open daily',
    ];

    /** @return array<string, mixed> */
    private static function page(): array
    {
        $pages = require dirname(__DIR__, 2) . '/database/content/service-pages.php';

        if (!is_array($pages)) {
            self::fail('service-pages.php did not return a list of pages.');
        }

        foreach ($pages as $page) {
            if (is_array($page) && ($page['key'] ?? null) === 'service.intussusception') {
                /** @var array<string, mixed> $page */
                return $page;
            }
        }

        self::fail('service-pages.php no longer contains service.intussusception.');
    }

    /** @return list<string> Every paragraph on the page, in order. */
    private static function paragraphs(): array
    {
        $sections = self::page()['sections'] ?? null;

        if (!is_array($sections)) {
            self::fail('The intussusception page has no sections.');
        }

        $paragraphs = [];

        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $entries = $section['paragraphs'] ?? [];

            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $paragraphs[] = $entry;
                }
            }
        }

        return $paragraphs;
    }

    public function testThePageCarriesTheApprovedParagraphWordForWord(): void
    {
        self::assertSame([self::APPROVED], self::paragraphs());
    }

    /**
     * The page has one section and one paragraph. This is a budget, not an aesthetic: D-03
     * authorises one paragraph, so a second one is a claim about a restricted pathway that no
     * authority has signed.
     */
    public function testThePageCarriesNoProseThatTheRecordDidNotApprove(): void
    {
        $sections = self::page()['sections'] ?? [];

        self::assertIsArray($sections);
        self::assertCount(1, $sections, 'D-03 authorises one section for this page.');
    }

    /**
     * The page is unpublishable, and the reason is the one D-03 gives.
     *
     * `toDraft()` is the same conversion `content:sync` performs, so this asserts the state an
     * import would produce on a fresh database — not just the state of this file.
     */
    public function testThePageCannotBePublishedWithoutTheSignOffTheDecisionRequires(): void
    {
        $page = self::page();

        self::assertArrayNotHasKey(
            'effective_on',
            $page,
            'An effective date would make the page publishable, and D-03 requires a sign-off first.',
        );

        $draft = (new ContentSource())->toDraft($page, '2026-11-23 00:00:00');

        self::assertFalse($draft->isPublishable());
        self::assertContains('effective date', $draft->missingForPublication());
    }

    /**
     * The emergency-safety instruction is the whole reason a page exists for a pathway that cannot
     * be booked. It is asserted separately so that removing it to "keep the page short" fails here
     * with a sentence about safety rather than with a diff.
     */
    public function testThePageKeepsTheInstructionToSeekEmergencyAssessment(): void
    {
        self::assertStringContainsString(
            'seek immediate assessment at a hospital or emergency department',
            implode(' ', self::paragraphs()),
        );
    }

    public function testThePageMakesNoAvailabilityClaim(): void
    {
        $text = strtolower(implode(' ', self::paragraphs()));

        foreach (self::UNAVAILABILITY_CLAIMS as $claim) {
            self::assertStringNotContainsString($claim, $text, 'The page claims availability: ' . $claim);
        }
    }
}
