<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Procedure;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue's read model: what a form is allowed to offer, and what it must
 * not guess.
 */
final class ProcedureTest extends TestCase
{
    public function testAnActiveElectiveProcedureIsOffered(): void
    {
        $procedure = $this->procedure(active: 1, category: 'elective');

        self::assertTrue($procedure->isElective());
        self::assertSame('Image-guided biopsy and FNAC', $procedure->label);
    }

    public function testAnInactiveProcedureIsNotOfferedEvenIfItIsElective(): void
    {
        self::assertFalse($this->procedure(active: 0, category: 'elective')->isElective());
    }

    public function testAnUrgentProcedureIsNeverAnElectiveOption(): void
    {
        // WF-04: the urgent pathway is not a public booking option, whatever the
        // row's active flag says.
        self::assertFalse($this->procedure(active: 1, category: 'urgent')->isElective());
    }

    public function testAProcedureWithNoApprovedFieldSetExposesNoFields(): void
    {
        self::assertSame([], $this->procedure()->extraFields());
    }

    public function testAnApprovedFieldSetIsDecoded(): void
    {
        $row = $this->row();
        $row['required_fields'] = '{"lesion_size":"required|max:40"}';
        $row['approved_by'] = 'Clinical lead';
        $row['approved_at'] = '2026-11-01 09:00:00';

        $procedure = Procedure::fromRow($row);

        self::assertSame(['lesion_size' => 'required|max:40'], $procedure->extraFields());
        self::assertSame('Clinical lead', $procedure->approvedBy);
        self::assertSame(2, $procedure->fieldSetVersion);
    }

    public function testUnreadableFieldJsonYieldsNoFieldsRatherThanAGuess(): void
    {
        $row = $this->row();
        $row['required_fields'] = 'not json';

        self::assertSame([], Procedure::fromRow($row)->extraFields());
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return [
            'code' => 'image_guided_biopsy',
            'label' => 'Image-guided biopsy and FNAC',
            'category' => 'elective',
            'default_duration_minutes' => 30,
            'required_fields' => null,
            'field_set_version' => 2,
            'approved_by' => null,
            'approved_at' => null,
            'active' => 1,
        ];
    }

    private function procedure(int $active = 1, string $category = 'elective'): Procedure
    {
        $row = $this->row();
        $row['active'] = $active;
        $row['category'] = $category;

        return Procedure::fromRow($row);
    }
}
