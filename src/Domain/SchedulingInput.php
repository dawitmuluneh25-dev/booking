<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A validated request to put an appointment somewhere.
 *
 * Both booking and rescheduling need the same three facts and the same refusals,
 * and a second hand-written copy of "is this a whole number of quanta" is how the
 * two drift until one of them accepts 17 minutes. Parsing lives here once.
 */
final readonly class SchedulingInput
{
    public const MIN_MINUTES = 15;

    public const MAX_MINUTES = 480;

    public function __construct(
        public int $resourceId,
        public int $durationMinutes,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
    ) {
    }

    /**
     * @param array<string, mixed> $input resource_id, starts_at (UTC), duration_minutes
     *
     * @throws ValidationException when any field is missing or not acceptable
     */
    public static function fromArray(array $input): self
    {
        $resourceId = self::integer($input, 'resource_id');
        $duration = self::integer($input, 'duration_minutes');
        $startsAt = self::timestamp(self::text($input, 'starts_at'));

        $errors = [];

        if ($resourceId === null || $resourceId < 1) {
            $errors['resource_id'] = 'Choose the room or suite to book.';
        }

        if ($duration === null || $duration < self::MIN_MINUTES || $duration > self::MAX_MINUTES) {
            $errors['duration_minutes'] = sprintf(
                'Duration must be between %d and %d minutes.',
                self::MIN_MINUTES,
                self::MAX_MINUTES,
            );
        } elseif ($duration % ResourceSchedule::SLOT_MINUTES !== 0) {
            $errors['duration_minutes'] = sprintf(
                'Duration must be a multiple of %d minutes.',
                ResourceSchedule::SLOT_MINUTES,
            );
        }

        if ($startsAt === null) {
            $errors['starts_at'] = 'Give the start time as YYYY-MM-DD HH:MM in UTC.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        \assert($resourceId !== null && $duration !== null && $startsAt !== null);

        $endsAt = $startsAt->modify('+' . $duration . ' minutes');

        if ($endsAt === false) {
            throw new ValidationException(['duration_minutes' => 'That duration is not a real time span.']);
        }

        return new self($resourceId, $duration, $startsAt, $endsAt);
    }

    /** @return list<string> */
    public function slotStarts(): array
    {
        return ResourceSchedule::quantumStarts($this->startsAt, $this->durationMinutes);
    }

    public function startsAtUtc(): string
    {
        return $this->startsAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function endsAtUtc(): string
    {
        return $this->endsAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $input */
    private static function integer(array $input, string $key): ?int
    {
        $value = $input[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^[0-9]{1,6}$/', trim($value)) === 1
            ? (int) trim($value)
            : null;
    }

    /** @param array<string, mixed> $input */
    private static function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private static function timestamp(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

        return $parsed === false || $parsed->format('Y-m-d H:i:s') !== $value ? null : $parsed;
    }
}
