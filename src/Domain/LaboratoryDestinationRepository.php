<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The laboratory catalogue (§19.1).
 *
 * Read by the specimen slice when it records a dispatch, and written only by
 * configuration. Rows are never deleted: a laboratory that closes is deactivated,
 * because a specimen sent there last year must not point at nothing.
 */
interface LaboratoryDestinationRepository
{
    /** @return list<LaboratoryDestination> */
    public function all(): array;

    /**
     * Only the ones a new dispatch may choose.
     *
     * @return list<LaboratoryDestination>
     */
    public function active(): array;

    public function findById(int $id): ?LaboratoryDestination;

    /**
     * The next serial in the series.
     *
     * Reads the highest existing serial rather than counting rows, so a
     * deactivated laboratory does not cause its number to be handed out twice.
     */
    public function nextSerial(): int;

    public function create(int $serialNo, string $occurredAtUtc): LaboratoryDestination;

    public function setActive(int $id, bool $active, string $occurredAtUtc): void;
}
