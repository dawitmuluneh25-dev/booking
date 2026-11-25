<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Actor;
use App\Domain\OperationalAppointment;
use App\Domain\OperationalAppointmentRepository;

/**
 * The five appointments in memory, seeded vacant like the migration seeds them.
 *
 * Seeding matters: a fake that started empty would let a use case pass here and fail against MySQL,
 * where every appointment is a row from the moment the migration runs.
 */
final class InMemoryOperationalAppointmentRepository implements OperationalAppointmentRepository
{
    /** @var array<string, array{holder: ?string, deputy: ?string, updatedBy: ?string, updatedAt: ?string}> */
    private array $rows = [];

    public function __construct()
    {
        foreach (OperationalAppointment::cases() as $appointment) {
            $this->rows[$appointment->value] = [
                'holder' => null,
                'deputy' => null,
                'updatedBy' => null,
                'updatedAt' => null,
            ];
        }
    }

    public function all(): array
    {
        return $this->rows;
    }

    public function save(string $roleKey, ?string $holder, ?string $deputy, Actor $actor, string $nowUtc): void
    {
        $this->rows[$roleKey] = [
            'holder' => $holder,
            'deputy' => $deputy,
            'updatedBy' => 'user:' . $actor->userId . ':' . $actor->role->value,
            'updatedAt' => $nowUtc,
        ];
    }
}
