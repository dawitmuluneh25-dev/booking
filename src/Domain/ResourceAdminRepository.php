<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The write side of resources and their weekly rules.
 *
 * Kept apart from `ScheduleRepository`, which is deliberately read-only: a caller
 * that can both reserve and configure through one object will eventually configure
 * inside a reservation. This interface also lists inactive resources, because an
 * administrator has to be able to see and revive what was switched off — the
 * scheduling read only ever wants what is bookable.
 */
interface ResourceAdminRepository
{
    /** @return list<Resource> every room and suite, active or not */
    public function all(): array;

    /**
     * @throws ValidationException the code is taken or malformed, or a field is invalid
     */
    public function create(string $code, string $name, string $kind, ?string $modality, bool $active): Resource;

    /**
     * Updates the things that can change. `code` is not among them: it is what a
     * report groups by, and renaming it would silently rewrite history.
     *
     * @throws ValidationException a field is invalid
     */
    public function update(int $id, string $name, string $kind, ?string $modality, bool $active): Resource;

    /**
     * The default week: rows with no validity range.
     *
     * @return array<int, array{opens: string, closes: string}|null> keyed by ISO weekday 1-7
     */
    public function defaultWeek(int $resourceId): array;

    /**
     * Replace the default week, leaving dated overrides untouched.
     *
     * @param array<int, array{opens: string, closes: string}|null> $week keyed by ISO weekday 1-7
     *
     * @throws ValidationException a window is malformed or ends before it starts
     */
    public function replaceDefaultWeek(int $resourceId, array $week): void;
}
