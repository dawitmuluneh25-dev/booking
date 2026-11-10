<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Resource;
use App\Domain\ResourceAdminRepository;
use App\Domain\ValidationException;

/**
 * Resource configuration in arrays, with the same refusals as the PDO version.
 *
 * The double keeps inactive rooms in `all()` and drops them from the scheduling
 * read's view only through the real `ScheduleRepository`, so a test that mixes
 * the two still proves the separation rather than assuming it.
 */
final class InMemoryResourceAdminRepository implements ResourceAdminRepository
{
    /** @var list<Resource> */
    private array $resources = [];

    /** @var array<int, array<int, array{opens: string, closes: string}|null>> */
    private array $weeks = [];

    private int $nextId = 1;

    public function seed(Resource $resource, ?int $id = null): Resource
    {
        $stored = new Resource(
            id: $id ?? $this->nextId++,
            code: $resource->code,
            name: $resource->name,
            kind: $resource->kind,
            modality: $resource->modality,
            isActive: $resource->isActive,
        );

        $this->resources[] = $stored;

        return $stored;
    }

    /** @return list<Resource> */
    public function all(): array
    {
        return $this->resources;
    }

    public function create(string $code, string $name, string $kind, ?string $modality, bool $active): Resource
    {
        foreach ($this->resources as $resource) {
            if ($resource->code === $code) {
                throw new ValidationException(['code' => 'Another room already uses that code.']);
            }
        }

        return $this->seed(new Resource(0, $code, $name, $kind, $modality, $active));
    }

    public function update(int $id, string $name, string $kind, ?string $modality, bool $active): Resource
    {
        foreach ($this->resources as $index => $resource) {
            if ($resource->id !== $id) {
                continue;
            }

            $updated = new Resource($id, $resource->code, $name, $kind, $modality, $active);
            $this->resources[$index] = $updated;

            return $updated;
        }

        throw new ValidationException(['name' => 'That room no longer exists.']);
    }

    /** @return array<int, array{opens: string, closes: string}|null> */
    public function defaultWeek(int $resourceId): array
    {
        $week = array_fill(1, 7, null);

        foreach ($this->weeks[$resourceId] ?? [] as $weekday => $window) {
            if (array_key_exists($weekday, $week)) {
                $week[$weekday] = $window;
            }
        }

        return $week;
    }

    /** @param array<int, array{opens: string, closes: string}|null> $week */
    public function replaceDefaultWeek(int $resourceId, array $week): void
    {
        foreach ($week as $weekday => $window) {
            if ($window === null) {
                continue;
            }

            if ($window['opens'] >= $window['closes']) {
                throw new ValidationException([
                    'week' => 'Day ' . $weekday . ': the closing time has to be after the opening time.',
                ]);
            }
        }

        $this->weeks[$resourceId] = $week;
    }
}
