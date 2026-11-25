<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ContentSource;
use PHPUnit\Framework\TestCase;

/**
 * C4: the five places where published service copy was thinner than HR-IR-CLIN-R1-001.
 *
 * The plan has carried these since 18 November as one line — "missing anticoagulant warning,
 * catheter-tampering warning, 'not an emergency service', 'do not wait for a response'" — which is
 * enough to act on and not enough to check. Each one is asserted here as the **package's own
 * sentence**, because the failure mode this guards against is not a page that omits a warning; it is
 * a page that carries a rewritten one, and "do not stop your medicines" and "do not stop
 * anticoagulants, antiplatelet medicines or other medicines without a specific clinical instruction"
 * are not the same instruction.
 *
 * `service.intussusception` is deliberately absent from this file. D-03 holds that page to one
 * paragraph, and `IntussusceptionCopyTest` enforces the budget; adding the "not an emergency service"
 * sentence there would be a second paragraph claiming something about a restricted pathway, which is
 * the decision's own prohibition rather than an oversight.
 */
final class ServicePageCopyAlignmentTest extends TestCase
{
    /** C-5 §E1. The published title dropped the first of the two approaches the page describes. */
    private const ABSCESS_TITLE = 'Image-Guided Abscess Aspiration and Drainage';

    /** C-5 §D8, last paragraph. */
    private const ANTICOAGULANT = 'Do not stop anticoagulants, antiplatelet medicines or other medicines '
        . 'without a specific clinical instruction. Do not apply a generic fasting or laboratory rule to '
        . 'your own procedure.';

    /** C-5 §E10, the paragraph in bold. */
    private const CATHETER = 'Do not attempt to flush, remove, reposition or otherwise manipulate a drainage '
        . 'catheter unless the responsible clinical team has specifically instructed and trained you to do so '
        . 'under an approved local protocol.';

    /** C-5 §C11. */
    private const FNAC_URGENT = 'If the patient experiences a serious or rapidly worsening problem or needs '
        . 'urgent assessment, seek medical care through the appropriate emergency service rather than waiting '
        . 'for an online response.';

    /** C-5 §D11. */
    private const BIOPSY_URGENT = 'If the patient develops a serious or rapidly worsening problem or needs '
        . 'urgent assessment, seek care through the appropriate emergency service immediately.';

    /** C-5 §E11, two paragraphs: the direction, and the sentence C4 names. */
    private const ABSCESS_URGENT = 'If the patient becomes acutely unwell, develops a serious or rapidly '
        . 'worsening problem, or requires urgent medical assessment, contact the treating hospital or '
        . 'emergency service immediately.';

    private const ABSCESS_WAIT = 'Do not wait for an online response.';

    private const URGENT_HEADING = 'When to contact the treating team or seek urgent care';

    /** @return array<string, mixed> */
    private static function page(string $key): array
    {
        $pages = require dirname(__DIR__, 2) . '/database/content/service-pages.php';

        if (!is_array($pages)) {
            self::fail('service-pages.php did not return a list of pages.');
        }

        foreach ($pages as $page) {
            if (is_array($page) && ($page['key'] ?? null) === $key) {
                /** @var array<string, mixed> $page */
                return $page;
            }
        }

        self::fail('service-pages.php does not contain ' . $key . '.');
    }

    /**
     * Every paragraph on a page, plus every FAQ answer, as one searchable string.
     */
    private static function text(string $key): string
    {
        $sections = self::page($key)['sections'] ?? null;

        if (!is_array($sections)) {
            self::fail($key . ' has no sections.');
        }

        $parts = [];

        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $paragraphs = $section['paragraphs'] ?? [];

            if (is_array($paragraphs)) {
                foreach ($paragraphs as $paragraph) {
                    if (is_string($paragraph)) {
                        $parts[] = $paragraph;
                    }
                }
            }

            $faq = $section['faq'] ?? [];

            if (is_array($faq)) {
                foreach ($faq as $entry) {
                    if (is_array($entry) && is_string($entry['answer'] ?? null)) {
                        $parts[] = $entry['answer'];
                    }
                }
            }
        }

        return implode(' ', $parts);
    }

    /** @return list<string> */
    private static function headings(string $key): array
    {
        $sections = self::page($key)['sections'] ?? [];

        if (!is_array($sections)) {
            return [];
        }

        $headings = [];

        foreach ($sections as $section) {
            if (is_array($section) && is_string($section['heading'] ?? null)) {
                $headings[] = $section['heading'];
            }
        }

        return $headings;
    }

    public function testTheAbscessTitleNamesBothApproachesThePageDescribes(): void
    {
        self::assertSame(self::ABSCESS_TITLE, self::page('service.abscess_drainage')['title']);
    }

    public function testTheBiopsyPageCarriesTheApprovedAnticoagulantWarning(): void
    {
        self::assertStringContainsString(self::ANTICOAGULANT, self::text('service.core_biopsy'));
    }

    public function testTheAbscessPageCarriesTheApprovedCatheterCareWarning(): void
    {
        self::assertStringContainsString(self::CATHETER, self::text('service.abscess_drainage'));
    }

    public function testEachProcedurePageCarriesItsOwnApprovedUrgentCareSection(): void
    {
        self::assertContains(self::URGENT_HEADING, self::headings('service.fnac'));
        self::assertContains(self::URGENT_HEADING, self::headings('service.core_biopsy'));
        self::assertContains(self::URGENT_HEADING, self::headings('service.abscess_drainage'));

        self::assertStringContainsString(self::FNAC_URGENT, self::text('service.fnac'));
        self::assertStringContainsString(self::BIOPSY_URGENT, self::text('service.core_biopsy'));
        self::assertStringContainsString(self::ABSCESS_URGENT, self::text('service.abscess_drainage'));
        self::assertStringContainsString(self::ABSCESS_WAIT, self::text('service.abscess_drainage'));
    }

    /**
     * Urgent-care information sits before the questions, not after them.
     *
     * A page whose emergency paragraph is the last thing above the footer is a page where the
     * instruction is technically present and practically missed. Position is the difference between
     * carrying a warning and displaying one.
     */
    public function testTheUrgentCareSectionComesBeforeTheQuestions(): void
    {
        foreach (['service.fnac', 'service.core_biopsy', 'service.abscess_drainage'] as $key) {
            $headings = self::headings($key);
            $urgent = array_search(self::URGENT_HEADING, $headings, true);
            $faq = array_search('Frequently asked questions', $headings, true);

            self::assertIsInt($urgent, $key . ' has no urgent-care section.');
            self::assertIsInt($faq, $key . ' has no questions section.');
            self::assertLessThan($faq, $urgent, $key . ' puts the questions before the urgent-care section.');
        }
    }

    /**
     * A corrected page declares its own version; an unchanged one keeps the package version.
     *
     * This is what makes the correction shippable at all: `content:sync` never rewrites a published
     * row, and the row's identity includes the version, so a page edited under version 1.0 would be
     * skipped by the very import meant to carry it.
     */
    public function testEveryCorrectedPageDeclaresVersionOnePointOne(): void
    {
        foreach (['service.fnac', 'service.core_biopsy', 'service.abscess_drainage'] as $key) {
            $page = self::page($key);

            self::assertSame('1.1', $page['version'] ?? null, $key . ' does not declare its correction.');
            self::assertSame('1.0', $page['superseded_version'] ?? null, $key . ' does not say what it replaces.');

            $draft = (new ContentSource())->toDraft($page, '2026-11-24 00:00:00');

            self::assertSame('1.1', $draft->version);
            self::assertSame('1.0', $draft->supersededVersion);
        }

        // The intussusception page joined this set on 25 November 2026: the D-03 sign-off arrived and
        // it was republished as 1.1 with C-5 §G8's disclaimer (D-14). While it could not be published
        // it deliberately had no version of its own; that guard belonged to its unpublishability and
        // left with it.
        $corrected = self::page('service.intussusception');

        self::assertSame('1.1', $corrected['version'] ?? null);
        self::assertSame('1.0', $corrected['superseded_version'] ?? null);
        self::assertSame(
            '1.1',
            (new ContentSource())->toDraft($corrected, '2026-11-25 00:00:00')->version,
        );
    }

    /** The instructions that were missing must not be re-worded into something gentler. */
    public function testTheUrgentCareSectionsUseThePackagesWordingRatherThanAParaphrase(): void
    {
        $forbidden = [
            'seek medical attention if concerned',
            'contact us if you have any concerns',
            'please call if worried',
        ];

        foreach (['service.fnac', 'service.core_biopsy', 'service.abscess_drainage'] as $key) {
            $text = strtolower(self::text($key));

            foreach ($forbidden as $phrase) {
                self::assertStringNotContainsString($phrase, $text, $key . ' paraphrases the approved wording.');
            }
        }
    }
}
