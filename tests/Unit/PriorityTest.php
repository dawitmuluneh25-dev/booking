<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Priority;
use PHPUnit\Framework\TestCase;

/**
 * The approved clinical priority model (HR-IR-CLIN-R1-001 §13.1, A-08).
 *
 * The value of these tests is the boundary, not the labels: three categories
 * exist, only two of them are requestable, and the requestable pair is exactly
 * what the intake form offers and the column accepts.
 */
final class PriorityTest extends TestCase
{
    public function testTheRequestableHalfIsRoutineAndExpedited(): void
    {
        self::assertSame(
            ['routine', 'expedited'],
            Priority::requestableValues(),
        );
    }

    public function testUrgentDirectCoordinationIsAClinicalCategoryAndNotRequestable(): void
    {
        self::assertFalse(Priority::UrgentDirectCoordination->isRequestable());
        self::assertTrue(Priority::UrgentDirectCoordination->requiresClinicalRationale());

        // It exists in the clinical vocabulary even though no form offers it.
        self::assertContains('urgent_direct_coordination', Priority::clinicalValues());
        self::assertNotContains('urgent_direct_coordination', Priority::requestableValues());
    }

    public function testOnlyRoutineIsExemptFromAReason(): void
    {
        self::assertFalse(Priority::Routine->requiresClinicalRationale());
        self::assertTrue(Priority::Expedited->requiresClinicalRationale());
    }

    public function testTheClinicalVocabularyIsTheApprovedThree(): void
    {
        self::assertSame(
            ['routine', 'expedited', 'urgent_direct_coordination'],
            Priority::clinicalValues(),
        );
    }

    public function testLabelsAreTheDocumentWording(): void
    {
        self::assertSame('Routine', Priority::Routine->label());
        self::assertSame('Expedited', Priority::Expedited->label());
        self::assertSame('Urgent direct coordination', Priority::UrgentDirectCoordination->label());
    }

    /**
     * A row written before the vocabulary changed is still shown as something.
     * Hiding it would leave a screen that describes a referral less completely
     * than the database does.
     */
    public function testAnUnknownStoredValueFallsBackToItself(): void
    {
        self::assertSame('Expedited', Priority::labelFor('expedited'));
        self::assertSame('priority', Priority::labelFor('priority'));
    }
}
