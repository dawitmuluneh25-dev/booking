<?php

declare(strict_types=1);

namespace App\Domain;

use App\Validation\Validator;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Booking use cases. Validation, normalisation and reference generation live
 * here so controllers stay thin and templates hold no business rules.
 */
final class BookingService
{
    public const SERVICES = [
        'consultation' => 'Consultation',
        'follow_up' => 'Follow-up',
        'procedure' => 'Procedure',
        'other' => 'Other',
    ];

    /** Length of one appointment and the granularity of the booking grid. */
    public const SLOT_MINUTES = 30;

    private const REFERENCE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly BookingRepository $repository,
        private readonly Validator $validator,
        private readonly DateTimeZone $timezone,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationException
     */
    public function request(array $input): Booking
    {
        $data = [
            'patient_name' => $this->text($input, 'patient_name'),
            'patient_phone' => $this->text($input, 'patient_phone'),
            'service' => $this->text($input, 'service'),
            'scheduled_at' => $this->text($input, 'scheduled_at'),
            'notes' => $this->text($input, 'notes'),
        ];

        $errors = $this->validator->validate($data, [
            'patient_name' => 'required|min:2|max:120',
            'patient_phone' => 'required|phone',
            'service' => 'required|in:' . implode(',', array_keys(self::SERVICES)),
            'scheduled_at' => 'required|datetime',
            'notes' => 'max:1000',
        ]);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $local = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $data['scheduled_at'], $this->timezone);
        if ($local === false) {
            throw new ValidationException(['scheduled_at' => 'Scheduled at must be a valid date and time.']);
        }

        if ((int) $local->format('i') % self::SLOT_MINUTES !== 0 || (int) $local->format('s') !== 0) {
            throw new ValidationException([
                'scheduled_at' => 'Choose a time on the half hour, for example 09:00 or 09:30.',
            ]);
        }

        $utc = $local->setTimezone(new DateTimeZone('UTC'));
        if ($utc < new DateTimeImmutable('-' . self::SLOT_MINUTES . ' minutes', new DateTimeZone('UTC'))) {
            throw new ValidationException(['scheduled_at' => 'Choose a time in the future.']);
        }

        $booking = new Booking(
            reference: $this->reference(),
            patientName: $data['patient_name'],
            patientPhone: $data['patient_phone'],
            service: $data['service'],
            scheduledAtUtc: $utc->format('Y-m-d H:i:s'),
            notes: $data['notes'] === '' ? null : $data['notes'],
            status: BookingStatus::Requested,
            createdAtUtc: gmdate('Y-m-d H:i:s'),
        );

        $this->repository->create($booking, $utc->format('Y-m-d H:i:s'));

        return $booking;
    }

    public function find(string $reference): ?Booking
    {
        return $this->repository->findByReference($reference);
    }

    /**
     * Confirm a request. Cancelling later releases the slot.
     *
     * @throws BookingNotFoundException|InvalidTransitionException
     */
    public function confirm(string $reference, string $actor): Booking
    {
        return $this->transition($reference, BookingStatus::Confirmed, $actor, null);
    }

    /**
     * Cancel a request or confirmed booking. The slot is released in the same
     * transaction, so the time becomes bookable again immediately.
     *
     * @throws BookingNotFoundException|InvalidTransitionException
     */
    public function cancel(string $reference, string $actor, ?string $reason = null): Booking
    {
        $reason = $reason === null ? null : trim($reason);

        return $this->transition($reference, BookingStatus::Cancelled, $actor, $reason === '' ? null : $reason);
    }

    /**
     * Mark a confirmed booking as completed. The slot stays claimed: the
     * appointment happened.
     *
     * @throws BookingNotFoundException|InvalidTransitionException
     */
    public function complete(string $reference, string $actor): Booking
    {
        return $this->transition($reference, BookingStatus::Completed, $actor, null);
    }

    /**
     * @throws BookingNotFoundException|InvalidTransitionException
     */
    private function transition(string $reference, BookingStatus $target, string $actor, ?string $reason): Booking
    {
        $actor = trim($actor);
        if ($actor === '' || mb_strlen($actor) > 120) {
            throw new \InvalidArgumentException('Actor must be between 1 and 120 characters.');
        }

        if ($reason !== null && mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('Reason must be at most 500 characters.');
        }

        $booking = $this->repository->findByReference($reference);
        if ($booking === null) {
            throw new BookingNotFoundException($reference);
        }

        if (!$booking->status->canTransitionTo($target)) {
            throw new InvalidTransitionException($booking->status, $target);
        }

        $this->repository->transition($booking, $target, $actor, $reason);

        return $booking->withStatus($target);
    }

    /** @return list<Booking> */
    public function upcoming(int $limit = 20): array
    {
        return $this->repository->upcoming($limit);
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private function reference(): string
    {
        $suffix = '';
        for ($i = 0; $i < 6; $i++) {
            $suffix .= self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)];
        }

        return 'BK-' . gmdate('ymd') . '-' . $suffix;
    }
}
