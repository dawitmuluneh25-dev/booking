<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\LaboratoryDestination;
use App\Domain\LaboratoryDestinationRepository;

/** In-memory laboratory catalogue, with the serial and active rules the MySQL one keeps. */
final class InMemoryLaboratoryDestinationRepository implements LaboratoryDestinationRepository
{
    /** @var array<int, LaboratoryDestination> */
    private array $destinations = [];

    private int $nextId = 1;

    public function all(): array
    {
        $destinations = array_values($this->destinations);

        usort(
            $destinations,
            static fn (LaboratoryDestination $left, LaboratoryDestination $right): int => $left->serialNo
                <=> $right->serialNo,
        );

        return $destinations;
    }

    /** @return list<LaboratoryDestination> */
    public function active(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (LaboratoryDestination $destination): bool => $destination->isActive,
        ));
    }

    public function findById(int $id): ?LaboratoryDestination
    {
        return $this->destinations[$id] ?? null;
    }

    public function nextSerial(): int
    {
        $highest = 0;

        foreach ($this->destinations as $destination) {
            $highest = max($highest, $destination->serialNo);
        }

        return $highest + 1;
    }

    public function create(int $serialNo, string $occurredAtUtc): LaboratoryDestination
    {
        foreach ($this->destinations as $destination) {
            if ($destination->serialNo === $serialNo) {
                throw new \PDOException('Duplicate entry ' . $serialNo . ' for key serial_no');
            }
        }

        $destination = new LaboratoryDestination($this->nextId, $serialNo, true);
        $this->destinations[$this->nextId] = $destination;
        $this->nextId++;

        return $destination;
    }

    public function setActive(int $id, bool $active, string $occurredAtUtc): void
    {
        $destination = $this->destinations[$id] ?? null;

        if ($destination === null) {
            return;
        }

        $this->destinations[$id] = $destination->withActive($active);
    }

    /** Seed a laboratory without going through the command, for page tests. */
    public function seed(int $serialNo, bool $active = true): LaboratoryDestination
    {
        $destination = new LaboratoryDestination($this->nextId, $serialNo, $active);
        $this->destinations[$this->nextId] = $destination;
        $this->nextId++;

        return $destination;
    }
}
