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

        $utc = $local->setTimezone(new DateTimeZone('UTC'));
        if ($utc < new DateTimeImmutable('-5 minutes', new DateTimeZone('UTC'))) {
            throw new ValidationException(['scheduled_at' => 'Choose a time in the future.']);
        }

        $booking = new Booking(
            reference: $this->reference(),
            patientName: $data['patient_name'],
            patientPhone: $data['patient_phone'],
            service: $data['service'],
            scheduledAtUtc: $utc->format('Y-m-d H:i:s'),
            notes: $data['notes'] === '' ? null : $data['notes'],
            status: 'requested',
            createdAtUtc: gmdate('Y-m-d H:i:s'),
        );

        $this->repository->create($booking);

        return $booking;
    }

    public function find(string $reference): ?Booking
    {
        return $this->repository->findByReference($reference);
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
