<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AddLaboratoryDestination;
use App\Domain\AuthorisationException;
use App\Domain\Role;
use App\Domain\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryLaboratoryDestinationRepository;

/**
 * The laboratory series (§19.1).
 *
 * The serial is assigned, never typed, so the properties worth testing are about
 * the sequence: it starts at one, it advances by one, a closed laboratory keeps its
 * number, and the ceiling refuses rather than wrapping round.
 */
final class AddLaboratoryDestinationTest extends TestCase
{
    private const NOW = '2026-11-13 10:00:00';

    private InMemoryLaboratoryDestinationRepository $destinations;

    private AddLaboratoryDestination $command;

    protected function setUp(): void
    {
        $this->destinations = new InMemoryLaboratoryDestinationRepository();
        $this->command = new AddLaboratoryDestination($this->destinations);
    }

    public function testTheFirstLaboratoryIsNumberOneAndItsCodeAndLabelAgree(): void
    {
        $destination = $this->command->execute($this->administrator(), self::NOW);

        self::assertSame(1, $destination->serialNo);
        self::assertSame('PATHOLOGY-LAB-1', $destination->code());
        self::assertSame('Pathology Lab 1', $destination->label());
        self::assertTrue($destination->isActive);
    }

    public function testTheSeriesAdvancesByOne(): void
    {
        $this->command->execute($this->administrator(), self::NOW);
        $second = $this->command->execute($this->administrator(), self::NOW);

        self::assertSame(2, $second->serialNo);
        self::assertSame('PATHOLOGY-LAB-2', $second->code());
    }

    /**
     * A closed laboratory keeps its number. Reissuing it would make two different
     * laboratories share one identifier in the specimen history.
     */
    public function testAClosedLaboratoryKeepsItsNumberAndTheSeriesMovesPastIt(): void
    {
        $first = $this->command->execute($this->administrator(), self::NOW);
        $this->destinations->setActive($first->id, false, self::NOW);

        $second = $this->command->execute($this->administrator(), self::NOW);

        self::assertSame(2, $second->serialNo);
        self::assertCount(1, $this->destinations->active());
        self::assertSame($first->id, $this->destinations->all()[0]->id);
    }

    public function testOnlyAConfiguringRoleMayAddOne(): void
    {
        $this->expectException(AuthorisationException::class);

        $this->command->execute(new Actor(4, Role::Staff), self::NOW);
    }

    public function testAFullSeriesIsRefusedWithASentenceRatherThanADriverError(): void
    {
        $this->destinations->seed(AddLaboratoryDestination::MAX_SERIAL);

        try {
            $this->command->execute($this->administrator(), self::NOW);
            self::fail('The series accepted a laboratory beyond its ceiling.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('serial_no', $exception->errors);
            self::assertStringContainsString('naming scheme', (string) $exception->errors['serial_no']);
        }
    }

    private function administrator(): Actor
    {
        return new Actor(1, Role::Admin);
    }
}
