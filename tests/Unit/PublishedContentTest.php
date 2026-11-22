<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ContentStatus;
use App\Domain\PublishedContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\ContentFactory;

/**
 * A content record's own rules (§17.5).
 *
 * The interesting cases are all about absence: a record with no reviewer, a record
 * whose review date has gone by, a record approved to start next month. Each of
 * them is a different reason not to show a page, and none of them may be resolved
 * by showing it anyway.
 */
final class PublishedContentTest extends TestCase
{
    public function testAPageWithEverythingAndNoDatesCannotBePublished(): void
    {
        $content = $this->content([
            'clinicalReviewer' => null,
            'effectiveOn' => null,
            'reviewOn' => null,
        ]);

        self::assertSame(
            ['clinical reviewer', 'effective date', 'review date'],
            $content->missingForPublication(),
            'The missing inputs are named, not counted.',
        );
        self::assertFalse($content->isPublishable());
    }

    public function testACompleteRecordIsPublishable(): void
    {
        self::assertTrue($this->content()->isPublishable());
        self::assertSame([], $this->content()->missingForPublication());
    }

    public function testAPageWithNoSectionsIsNotPublishableEvenWhenTheProvenanceIsComplete(): void
    {
        $content = $this->content(['body' => json_encode([], JSON_THROW_ON_ERROR)]);

        self::assertFalse($content->hasBody());
        self::assertFalse($content->isPublishable());
    }

    public function testADraftIsNotServedHoweverCompleteItIs(): void
    {
        self::assertFalse($this->content()->isServableOn('2026-11-13'));
    }

    public function testAPublishedPageIsServedBetweenItsEffectiveAndReviewDates(): void
    {
        $content = $this->published();

        self::assertTrue($content->isServableOn('2026-11-13'), 'The effective date itself counts.');
        self::assertTrue($content->isServableOn('2027-11-01'));
    }

    public function testAPublishedPageIsNotServedBeforeItsEffectiveDate(): void
    {
        // Approved to apply from December: showing it in November would show
        // something nobody said was live yet.
        $content = $this->published(['effectiveOn' => '2026-12-01']);

        self::assertFalse($content->isServableOn('2026-11-30'));
    }

    public function testAPublishedPageStopsBeingServedOnceItsReviewDatePasses(): void
    {
        $content = $this->published(['reviewOn' => '2027-11-13']);

        self::assertTrue($content->isServableOn('2027-11-13'), 'The review date itself is still current.');
        self::assertTrue($content->isExpiredOn('2027-11-14'));
        self::assertFalse($content->isServableOn('2027-11-14'));
    }

    public function testAWithdrawnPageIsNotServed(): void
    {
        $content = $this->content([
            'status' => ContentStatus::Withdrawn,
            'publishedAtUtc' => '2026-11-13 08:00:00',
            'publishedByUserId' => 1,
            'withdrawnAtUtc' => '2026-11-14 08:00:00',
            'withdrawnByUserId' => 1,
        ]);

        self::assertFalse($content->isServableOn('2026-11-15'));
    }

    public function testTheSectionsCarryEachShapeThePagesUse(): void
    {
        $body = json_encode([
            ['heading' => 'What it is', 'paragraphs' => ['A paragraph.']],
            ['heading' => 'Information', 'bullets' => ['A bullet.']],
            ['heading' => 'Questions', 'faq' => [['question' => 'Why?', 'answer' => 'Because.']]],
        ], JSON_THROW_ON_ERROR);

        $sections = $this->content(['body' => $body])->sections();

        self::assertCount(3, $sections);
        self::assertSame(['A paragraph.'], $sections[0]['paragraphs']);
        self::assertSame(['A bullet.'], $sections[1]['bullets']);
        self::assertSame([['question' => 'Why?', 'answer' => 'Because.']], $sections[2]['faq']);
    }

    public function testAMalformedBodyYieldsNoSectionsRatherThanAnException(): void
    {
        foreach (['not json', '{"heading":"x"}', '[{"paragraphs":["no heading"]}]'] as $body) {
            $content = $this->content(['body' => $body]);

            self::assertSame([], $content->sections(), $body . ' produced sections.');
            self::assertFalse($content->isServableOn('2026-11-13'));
        }
    }

    public function testTheProvenanceLineNamesTheReviewerTheApproverAndTheApprovalDate(): void
    {
        $line = $this->published()->provenance();

        self::assertStringContainsString('Clinically reviewed by Dr Reviewer', $line);
        self::assertStringContainsString('approved by Dawit Muluneh', $line);
        self::assertStringContainsString('13 November 2026', $line);

        // The whole sentence, because the separators are the part that goes wrong: a
        // space before the comma or a missing one after the year both read as typos.
        self::assertSame(
            'Clinically reviewed by Dr Reviewer, approved by Dawit Muluneh on 13 November 2026.',
            $line,
        );

        // The review date moved to the document-control block (D-01). Saying it in both places was
        // how the two copies came to disagree, so the line must not carry it any more.
        self::assertStringNotContainsString('Due for review', $line);
    }

    /**
     * D-01: the effective date and the review date are visible fields, and they come from the record.
     *
     * The labels are part of the assertion because a page that prints "2027-01-12" with no label is
     * a date a reader has to guess the meaning of.
     */
    public function testTheDocumentControlBlockCarriesTheEffectiveDateAndTheReviewDate(): void
    {
        self::assertSame(
            [
                ['label' => 'Effective from', 'value' => '13 November 2026'],
                ['label' => 'Next review', 'value' => '13 November 2027'],
            ],
            $this->published()->documentControl(),
        );
    }

    public function testADateTheRecordDoesNotHoldIsNotPrintedAsAnEmptyField(): void
    {
        $content = $this->content(['effectiveOn' => null, 'reviewOn' => null]);

        self::assertSame([], $content->documentControl());
    }

    public function testAnUnparseableDateIsShownAsStoredRatherThanBlanked(): void
    {
        self::assertSame('not a date', PublishedContent::humanDate('not a date'));
        self::assertSame('12 January 2027', PublishedContent::humanDate('2027-01-12'));
    }

    /**
     * The per-audience rule from D-01, asserted on the object rather than on the file.
     *
     * `policy.` pages do not owe a clinical reviewer; every other key still does. The negative case
     * matters as much as the positive one: an exemption that leaked to `service.` pages would let
     * clinical copy publish on nobody's signature.
     */
    public function testAPolicyPageDoesNotOweAClinicalReviewerAndClinicalCopyStillDoes(): void
    {
        $policy = ContentFactory::content('policy.privacy', [
            'clinicalReviewer' => null,
            'approver' => null,
            'approvedOn' => null,
        ]);

        self::assertTrue($policy->isPolicy());
        self::assertSame(['approver', 'approval date'], $policy->missingForPublication());

        $service = ContentFactory::content('service.fnac', ['clinicalReviewer' => null]);

        self::assertFalse($service->isPolicy());
        self::assertContains('clinical reviewer', $service->missingForPublication());
    }

    /**
     * One person holding both roles is stated once, not twice.
     *
     * The record keeps two fields because §17.5 asks two questions; the sentence a
     * patient reads answers them together. Printing the same name on both sides of a
     * comma reads as a copy-paste error, and the reader is left wondering who checked
     * the checker.
     */
    public function testOnePersonHoldingBothRolesIsNamedOnce(): void
    {
        $line = $this->published([
            'clinicalReviewer' => 'Dawit Muluneh',
            'approver' => 'Dawit Muluneh',
        ])->provenance();

        self::assertSame(
            'Reviewed and approved by Dawit Muluneh on 13 November 2026.',
            $line,
        );
        self::assertStringNotContainsString('Clinically reviewed by', $line);
    }

    /**
     * The same signature typed twice is the same signature: case and spacing are not
     * a second reviewer.
     */
    public function testTheSignatureIsComparedWithoutCaseOrRunsOfSpaces(): void
    {
        $line = $this->published([
            'clinicalReviewer' => '  dawit   MULUNEH ',
            'approver' => 'Dawit Muluneh',
        ])->provenance();

        self::assertSame(
            'Reviewed and approved by dawit MULUNEH on 13 November 2026.',
            $line,
        );
    }

    /**
     * §6.2: an approver who is not on the record is not implied by a reviewer who is.
     */
    public function testAReviewerWithNoApproverMakesNoApprovalClaim(): void
    {
        $line = $this->published([
            'clinicalReviewer' => 'Dr Reviewer',
            'approver' => null,
            'approvedOn' => null,
        ])->provenance();

        self::assertSame('Clinically reviewed by Dr Reviewer.', $line);
        self::assertStringNotContainsString('approved', $line);
    }

    public function testTheSlugIsTheLastSegmentOfTheContentKey(): void
    {
        self::assertSame('fnac', $this->content(['contentKey' => 'service.fnac'])->slug());
    }

    /** @param array<string, mixed> $overrides */
    private function content(array $overrides = []): PublishedContent
    {
        return ContentFactory::content('service.fnac', $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function published(array $overrides = []): PublishedContent
    {
        return ContentFactory::published('service.fnac', $overrides);
    }
}
