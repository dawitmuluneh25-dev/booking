<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\BookingNotFoundException;
use App\Domain\BookingRepository;
use App\Domain\BookingService;
use App\Domain\BookingStatus;
use App\Domain\InvalidTransitionException;
use App\Domain\NotificationChannel;
use App\Domain\NotificationTemplate;
use App\Domain\SlotUnavailableException;
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
        self::assertSame(BookingStatus::Requested, $booking->status);
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

    public function testItClaimsTheRequestedSlotInUtc(): void
    {
        $repository = new InMemoryBookingRepository();
        $this->service($repository, 'Africa/Addis_Ababa')->request([
            'patient_name' => 'Ada Lovelace',
            'patient_phone' => '+251906373154',
            'service' => 'consultation',
            'scheduled_at' => '2030-05-01T09:30',
        ]);

        self::assertSame(['2030-05-01 06:30:00'], array_keys($repository->slots));
    }

    public function testItRejectsATimeOffTheSlotGrid(): void
    {
        $service = $this->service(new InMemoryBookingRepository());

        try {
            $service->request([
                'patient_name' => 'Ada Lovelace',
                'patient_phone' => '+251906373154',
                'service' => 'consultation',
                'scheduled_at' => '2030-05-01T09:15',
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('scheduled_at', $exception->errors);
            self::assertStringContainsString('half hour', $exception->errors['scheduled_at']);
        }
    }

    public function testItSurfacesASlotConflict(): void
    {
        $repository = new InMemoryBookingRepository();
        $service = $this->service($repository, 'Africa/Addis_Ababa');

        $payload = [
            'patient_name' => 'Ada Lovelace',
            'patient_phone' => '+251906373154',
            'service' => 'consultation',
            'scheduled_at' => '2030-05-01T09:30',
        ];

        $service->request($payload);

        $this->expectException(SlotUnavailableException::class);
        $service->request($payload);
    }

    public function testItEnqueuesARequestNotificationWithNoClinicalDetail(): void
    {
        $repository = new InMemoryBookingRepository();
        $this->requestAt($this->service($repository, 'Africa/Addis_Ababa'), '2030-05-01T09:30');

        self::assertCount(1, $repository->messages);
        self::assertSame(NotificationTemplate::BookingRequested, $repository->messages[0]->template);
        self::assertSame(NotificationChannel::Sms, $repository->messages[0]->channel);
        self::assertSame(
            ['reference', 'scheduled_at', 'service'],
            array_keys($repository->messages[0]->payload),
        );
        self::assertStringContainsString('09:30', $repository->messages[0]->payload['scheduled_at']);
        self::assertStringNotContainsString('UTC', $repository->messages[0]->payload['scheduled_at']);
    }

    public function testConfirmAndCancelEnqueueTheirOwnNotifications(): void
    {
        $repository = new InMemoryBookingRepository();
        $service = $this->service($repository, 'Africa/Addis_Ababa');
        $booking = $this->requestAt($service, '2030-05-01T09:30');

        $service->confirm($booking->reference, 'cli:test');
        $service->cancel($booking->reference, 'cli:test');

        self::assertSame(
            [
                NotificationTemplate::BookingRequested,
                NotificationTemplate::BookingConfirmed,
                NotificationTemplate::BookingCancelled,
            ],
            array_map(static fn ($message): NotificationTemplate => $message->template, $repository->messages),
        );
    }

    public function testCompletingABookingEnqueuesNothing(): void
    {
        $repository = new InMemoryBookingRepository();
        $service = $this->service($repository, 'Africa/Addis_Ababa');
        $booking = $this->requestAt($service, '2030-05-01T09:30');

        $service->confirm($booking->reference, 'cli:test');
        $service->complete($booking->reference, 'cli:test');

        self::assertCount(2, $repository->messages);
    }

    public function testItConfirmsARequestedBookingAndKeepsTheSlot(): void
    {
        $repository = new InMemoryBookingRepository();
        $service = $this->service($repository, 'Africa/Addis_Ababa');
        $booking = $this->requestAt($service, '2030-05-01T09:30');

        $confirmed = $service->confirm($booking->reference, 'cli:test');

        self::assertSame(BookingStatus::Confirmed, $confirmed->status);
        self::assertSame(BookingStatus::Confirmed, $service->find($booking->reference)?->status);
        self::assertArrayHasKey('2030-05-01 06:30:00', $repository->slots);
        self::assertSame('confirmed', $repository->events[0]['to']);
        self::assertSame('cli:test', $repository->events[0]['actor']);
    }

    public function testItReleasesTheSlotWhenCancelled(): void
    {
        $repository = new InMemoryBookingRepository();
        $service = $this->service($repository, 'Africa/Addis_Ababa');
        $booking = $this->requestAt($service, '2030-05-01T09:30');

        $service->cancel($booking->reference, 'cli:test', 'patient request');

        self::assertSame(BookingStatus::Cancelled, $service->find($booking->reference)?->status);
        self::assertArrayNotHasKey('2030-05-01 06:30:00', $repository->slots);
        self::assertSame('patient request', $repository->events[0]['reason']);
    }

    public function testItCompletesAConfirmedBookingAndKeepsTheSlot(): void
    {
        $repository = new InMemoryBookingRepository();
        $service = $this->service($repository, 'Africa/Addis_Ababa');
        $booking = $this->requestAt($service, '2030-05-01T09:30');

        $service->confirm($booking->reference, 'cli:test');
        $completed = $service->complete($booking->reference, 'cli:test');

        self::assertSame(BookingStatus::Completed, $completed->status);
        self::assertArrayHasKey('2030-05-01 06:30:00', $repository->slots);
    }

    public function testItRefusesToConfirmACancelledBooking(): void
    {
        $service = $this->service(new InMemoryBookingRepository());
        $booking = $this->requestAt($service, '2030-05-01T09:30');
        $service->cancel($booking->reference, 'cli:test');

        $this->expectException(InvalidTransitionException::class);
        $service->confirm($booking->reference, 'cli:test');
    }

    public function testItRejectsAnUnknownBookingForTransitions(): void
    {
        $this->expectException(BookingNotFoundException::class);

        $this->service(new InMemoryBookingRepository())->cancel('BK-000000-ZZZZZZ', 'cli:test');
    }

    public function testItValidatesTheActorLabel(): void
    {
        $service = $this->service(new InMemoryBookingRepository());
        $booking = $this->requestAt($service, '2030-05-01T09:30');

        $this->expectException(\InvalidArgumentException::class);
        $service->confirm($booking->reference, '');
    }

    public function testItReturnsNullForAnUnknownReference(): void
    {
        self::assertNull($this->service(new InMemoryBookingRepository())->find('BK-000000-XXXXXX'));
    }

    private function service(BookingRepository $repository, string $timezone = 'UTC'): BookingService
    {
        return new BookingService($repository, new Validator(), new DateTimeZone($timezone));
    }

    private function requestAt(BookingService $service, string $localDateTime): \App\Domain\Booking
    {
        return $service->request([
            'patient_name' => 'Ada Lovelace',
            'patient_phone' => '+251906373154',
            'service' => 'consultation',
            'scheduled_at' => $localDateTime,
        ]);
    }
}
