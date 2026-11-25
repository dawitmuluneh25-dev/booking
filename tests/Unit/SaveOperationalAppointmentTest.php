<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AuditAction;
use App\Domain\AuthorisationException;
use App\Domain\OperationalAppointment;
use App\Domain\Role;
use App\Domain\SaveOperationalAppointment;
use App\Domain\ValidationException;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryOperationalAppointmentRepository;
use Tests\Support\InlineTransaction;
use Tests\Support\RecordingAuditTrail;

/**
 * D-05's three rules: a real appointment, a deputy who is somebody else, and vacancy as one value.
 *
 * Every one of them is a rule somebody could argue against until they have seen the failure. An
 * invented role key is a row that never appears on the screen; the same name twice is a rota with no
 * cover that reads as covered; and an empty string stored as a holder is a vacancy that the coverage
 * count cannot see.
 */
final class SaveOperationalAppointmentTest extends TestCase
{
    private const NOW = '2026-11-25 21:00:00';

    private InMemoryOperationalAppointmentRepository $appointments;

    private RecordingAuditTrail $audit;

    private SaveOperationalAppointment $save;

    protected function setUp(): void
    {
        $this->appointments = new InMemoryOperationalAppointmentRepository();
        $this->audit = new RecordingAuditTrail();

        $this->save = new SaveOperationalAppointment(
            $this->appointments,
            new Validator(),
            new InlineTransaction(),
            $this->audit,
        );
    }

    public function testItRecordsAHolderAndADeputy(): void
    {
        $this->save->execute($this->admin(), [
            'role' => OperationalAppointment::OperationsLead->value,
            'holder' => 'Trede Agua',
            'deputy' => 'Dawit Muluneh',
        ], self::NOW);

        $row = $this->appointments->all()[OperationalAppointment::OperationsLead->value];

        self::assertSame('Trede Agua', $row['holder']);
        self::assertSame('Dawit Muluneh', $row['deputy']);
        self::assertSame('user:1:admin', $row['updatedBy']);
        self::assertSame(self::NOW, $row['updatedAt']);
    }

    public function testTheChangeIsAuditedWithBothSides(): void
    {
        $this->save->execute($this->admin(), [
            'role' => OperationalAppointment::ClinicalCoordinationOfficer->value,
            'holder' => 'Dawit Muluneh',
            'deputy' => 'Trede Agua',
        ], self::NOW);

        $entries = $this->audit->entries();

        self::assertCount(1, $entries);
        self::assertSame(AuditAction::OperationalAppointmentChanged, $entries[0]->action);
        self::assertSame('operational_appointment', $entries[0]->objectType);
        self::assertSame(OperationalAppointment::ClinicalCoordinationOfficer->value, $entries[0]->objectRef);
        self::assertSame(['holder' => null, 'deputy' => null], $entries[0]->before);
        self::assertSame(['holder' => 'Dawit Muluneh', 'deputy' => 'Trede Agua'], $entries[0]->after);
    }

    public function testAnAppointmentTheEnumDoesNotDeclareIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $this->save->execute($this->admin(), [
            'role' => 'chief_of_everything',
            'holder' => 'Somebody',
            'deputy' => 'Somebody Else',
        ], self::NOW);
    }

    public function testTheDeputyCannotBeTheHolder(): void
    {
        try {
            $this->save->execute($this->admin(), [
                'role' => OperationalAppointment::ReferralIntakeCoordinator->value,
                'holder' => 'Trede Agua',
                'deputy' => 'Trede Agua',
            ], self::NOW);
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('deputy', $exception->errors);
            self::assertSame([], $this->audit->entries(), 'A refused change writes no audit row.');

            return;
        }

        self::fail('The same person cannot be their own deputy.');
    }

    public function testAnEmptyFieldIsAVacancyRatherThanAnEmptyString(): void
    {
        $this->save->execute($this->admin(), [
            'role' => OperationalAppointment::OperationsLead->value,
            'holder' => '   ',
            'deputy' => '',
        ], self::NOW);

        $row = $this->appointments->all()[OperationalAppointment::OperationsLead->value];

        self::assertNull($row['holder']);
        self::assertNull($row['deputy']);
    }

    public function testAOneCharacterNameIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $this->save->execute($this->admin(), [
            'role' => OperationalAppointment::OperationsLead->value,
            'holder' => 'X',
            'deputy' => 'Trede Agua',
        ], self::NOW);
    }

    public function testARoleWithoutTheConfigurationPermissionCannotAppointAnybody(): void
    {
        $this->expectException(AuthorisationException::class);

        $this->save->execute(new Actor(9, Role::Staff), [
            'role' => OperationalAppointment::OperationsLead->value,
            'holder' => 'Trede Agua',
            'deputy' => 'Dawit Muluneh',
        ], self::NOW);
    }

    private function admin(): Actor
    {
        return new Actor(1, Role::Admin);
    }
}
