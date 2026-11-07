<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\BookingRepository;
use App\Domain\BookingService;
use App\Domain\ValidationException;
use App\Validation\Validator;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryBookingRepository;

final class BookingServiceTest extends TestCase
{
    public function testItPersistsARequestInUtc(): void
    {
        $repository = new InMemoryBookingRepository();
        $service = $this->service($repository, 'Africa/Addis_Ababa');

        $booking = $service->request([
            'patient_name' => 'Ada Lovelace',
            'patient_phone' => '+251 906 373154',
            'service' => 'consultation',
            'scheduled_at' => '2030-05-01T09:30',
            'notes' => 'First visit',
        ]);

        self::assertCount(1, $repository->created);
        self::assertSame('2030-05-01 06:30:00', $booking->scheduledAtUtc);
        self::assertSame('requested', $booking->status);
        self::assertMatchesRegularExpression('/^BK-\d{6}-[A-Z2-9]{6}$/', $booking->reference);
    }

    public function testItRejectsAnUnknownService(): void
    {
        $service = $this->service(new InMemoryBookingRepository());

        $this->expectException(ValidationException::class);

        $service->request([
            'patient_name' => 'Ada Lovelace',
            'patient_phone' => '+251906373154',
            'service' => 'surgery',
            'scheduled_at' => '2030-05-01T09:30',
        ]);
    }

    public function testItRejectsAPastDate(): void
    {
        $service = $this->service(new InMemoryBookingRepository());

        $this->expectException(ValidationException::class);

        $service->request([
            'patient_name' => 'Ada Lovelace',
            'patient_phone' => '+251906373154',
            'service' => 'consultation',
            'scheduled_at' => '2020-01-01T09:00',
        ]);
    }

    public function testItTrimsAndDropsEmptyNotes(): void
    {
        $repository = new InMemoryBookingRepository();
        $booking = $this->service($repository)->request([
            'patient_name' => '  Ada Lovelace  ',
            'patient_phone' => '+251906373154',
            'service' => 'other',
            'scheduled_at' => '2030-05-01T09:30',
            'notes' => '   ',
        ]);

        self::assertSame('Ada Lovelace', $booking->patientName);
        self::assertNull($booking->notes);
    }

    public function testItReturnsNullForAnUnknownReference(): void
    {
        self::assertNull($this->service(new InMemoryBookingRepository())->find('BK-000000-XXXXXX'));
    }

    private function service(BookingRepository $repository, string $timezone = 'UTC'): BookingService
    {
        return new BookingService($repository, new Validator(), new DateTimeZone($timezone));
    }
}
