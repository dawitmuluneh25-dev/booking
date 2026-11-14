<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AppointmentController;
use App\Domain\Actor;
use App\Domain\Appointment;
use App\Domain\AppointmentStatus;
use App\Domain\Referral;
use App\Domain\ReferralStatus;
use App\Domain\RecordProcedureMilestone;
use App\Domain\Role;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\PasswordHasher;
use App\Support\View;
use App\Validation\Validator;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryAppointmentRepository;
use Tests\Support\InMemoryConsentRepository;
use Tests\Support\InMemoryDocumentRepository;
use Tests\Support\InMemoryProcedureMilestoneRepository;
use Tests\Support\InMemoryReferralRepository;
use Tests\Support\InMemoryUserRepository;
use Tests\Support\InlineTransaction;
use Tests\Support\RecordingAuditTrail;

/**
 * The procedure day as staff see it (BL-C3).
 *
 * These render the real templates, because the point of the slice is a page: a
 * timeline nobody can read and a form nobody can find are the same failure as no
 * timeline at all.
 */
final class AppointmentPageTest extends TestCase
{
    private const ZONE = 'Africa/Addis_Ababa';

    private const CLINIC_REFERENCE = 'RF-2026-0001';

    private InMemoryUserRepository $users;

    private InMemoryAppointmentRepository $appointments;

    private InMemoryProcedureMilestoneRepository $milestones;

    private InMemoryReferralRepository $referrals;

    private int $referralId = 0;

    private AppointmentController $controller;

    private string $password = 'correct horse';

    protected function setUp(): void
    {
        $_SESSION = [];

        $this->users = new InMemoryUserRepository();
        $hash = (new PasswordHasher())->hash($this->password);

        $this->users->seed('clerk@example.test', 'Sam Reception', Role::Staff, $hash);
        $this->users->seed('lead@example.test', 'Dr Selam Bekele', Role::Radiologist, $hash);

        $this->appointments = new InMemoryAppointmentRepository([1 => 'IR Suite 1']);
        $this->milestones = new InMemoryProcedureMilestoneRepository();
        $this->referrals = new InMemoryReferralRepository();

        $referral = $this->referrals->create(
            $this->referralEntity(),
            new Actor(1, Role::Staff),
            '2026-11-01 08:00:00',
        );
        $this->referralId = $referral->id;

        $this->signIn('clerk@example.test');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheQueueShowsTodaysProceduresAndWhatIsExpectedNext(): void
    {
        $today = $this->bookOn('today', 'ABC123');

        $body = $this->body($this->controller->index(
            Request::create('GET', '/appointments', ['date' => $this->clinicDate('today')]),
        ));

        self::assertStringContainsString($today->reference, $body);
        self::assertStringContainsString('Selamawit Girma', $body);
        // Nothing has been recorded yet, so the next expected event is arrival.
        self::assertStringContainsString('Patient arrival', $body);
        self::assertStringContainsString('0 of 5', $body);
    }

    public function testTheQueueIsOneDayAndExcludesCancelledWork(): void
    {
        $today = $this->bookOn('today', 'TODAY1');
        $tomorrow = $this->bookOn('+1 day', 'TOMORO', 'BK-261121-TOMORO');

        $body = $this->body($this->controller->index(
            Request::create('GET', '/appointments', ['date' => $this->clinicDate('today')]),
        ));

        self::assertStringContainsString($today->reference, $body);
        self::assertStringNotContainsString($tomorrow->reference, $body);
    }

    /**
     * AC-1: one timeline. The room-state row that the arrival produced is not
     * printed beside the arrival itself — the event is the record and the status
     * row is its receipt.
     */
    public function testTheTimelinePrintsEachEventOnce(): void
    {
        $appointment = $this->bookOn('today', 'ABC123');

        $this->controller->recordMilestone(
            Request::create('POST', '/appointments/' . $appointment->reference . '/milestones', [], [
                'kind' => 'arrival',
                Csrf::FIELD => Csrf::token(),
            ]),
            ['reference' => $appointment->reference],
        );

        $body = $this->body($this->controller->show(
            Request::create('GET', '/appointments/' . $appointment->reference),
            ['reference' => $appointment->reference],
        ));

        // The arrival's own status row would be the same minute printed twice.
        self::assertStringNotContainsString('Status: Checked in', $body);
        self::assertStringContainsString('Patient arrival', $body);
        self::assertStringContainsString('Sam Reception', $body);
    }

    public function testTheTimelineKeepsStateChangesThatNoEventExplains(): void
    {
        $appointment = $this->bookOn('today', 'ABC123');

        $this->appointments->transition(
            $appointment,
            AppointmentStatus::Cancelled,
            new Actor(1, Role::Staff),
            'Patient rang to cancel; the room is free.',
            '2026-11-20 07:30:00',
        );

        $body = $this->body($this->controller->show(
            Request::create('GET', '/appointments/' . $appointment->reference),
            ['reference' => $appointment->reference],
        ));

        self::assertStringContainsString('Status: Cancelled', $body);
        self::assertStringContainsString('Patient rang to cancel', $body);
    }

    public function testTheFormOffersReceptionOnlyWhatReceptionMayRecord(): void
    {
        $appointment = $this->bookOn('today', 'ABC123');

        $body = $this->body($this->controller->show(
            Request::create('GET', '/appointments/' . $appointment->reference),
            ['reference' => $appointment->reference],
        ));

        self::assertStringContainsString('value="arrival"', $body);
        self::assertStringNotContainsString('value="procedure_started"', $body);
        self::assertStringNotContainsString('value="consent_verified"', $body);
    }

    /**
     * The other half of the same split: once the patient is here, the clinical role
     * is offered the events reception may not write — and not the arrival, which is
     * already recorded and belongs to the desk.
     */
    public function testTheFormOffersTheClinicalRoleWhatTheDeskCannotRecord(): void
    {
        $appointment = $this->bookOn('today', 'ABC123');

        $this->appointments->transition(
            $appointment,
            AppointmentStatus::CheckedIn,
            new Actor(1, Role::Staff),
            null,
            '2026-11-20 09:00:00',
        );

        $this->signIn('lead@example.test');

        $body = $this->body($this->controller->show(
            Request::create('GET', '/appointments/' . $appointment->reference),
            ['reference' => $appointment->reference],
        ));

        self::assertStringNotContainsString('value="arrival"', $body);
        self::assertStringContainsString('value="procedure_started"', $body);
        self::assertStringContainsString('value="consent_verified"', $body);
    }

    public function testRecordingOutOfOrderWithoutAReasonIsRefusedOnTheScreen(): void
    {
        $appointment = $this->bookOn('today', 'ABC123');

        $this->appointments->transition(
            $appointment,
            AppointmentStatus::CheckedIn,
            new Actor(1, Role::Staff),
            null,
            '2026-11-20 09:00:00',
        );

        $this->signIn('lead@example.test');

        $response = $this->controller->recordMilestone(
            Request::create('POST', '/appointments/' . $appointment->reference . '/milestones', [], [
                'kind' => 'procedure_started',
                Csrf::FIELD => Csrf::token(),
            ]),
            ['reference' => $appointment->reference],
        );

        self::assertSame(422, $response->status);
        self::assertStringContainsString('out of sequence', $response->body());
        self::assertSame([], $this->milestones->all());
    }

    public function testAPostFromARoleThatMayNotRecordThatEventIsForbidden(): void
    {
        $appointment = $this->bookOn('today', 'ABC123');

        $this->expectException(HttpException::class);

        $this->controller->recordMilestone(
            Request::create('POST', '/appointments/' . $appointment->reference . '/milestones', [], [
                'kind' => 'procedure_started',
                Csrf::FIELD => Csrf::token(),
            ]),
            ['reference' => $appointment->reference],
        );
    }

    public function testACaseWithNothingLeftToRecordSaysWhy(): void
    {
        $appointment = $this->bookOn('today', 'ABC123');

        foreach (
            [
            AppointmentStatus::CheckedIn,
            AppointmentStatus::PreprocedureVerification,
            AppointmentStatus::Ready,
            AppointmentStatus::InProgress,
            AppointmentStatus::Completed,
            ] as $status
        ) {
            $appointment = $this->appointments->transition(
                $appointment,
                $status,
                new Actor(1, Role::Staff),
                null,
                '2026-11-20 09:00:00',
            );
        }

        $body = $this->body($this->controller->show(
            Request::create('GET', '/appointments/' . $appointment->reference),
            ['reference' => $appointment->reference],
        ));

        self::assertStringContainsString('Nothing can be recorded from this screen right now', $body);
    }

    public function testTheReferralAndItsDocumentsAreNamedWithoutTheirContents(): void
    {
        $appointment = $this->bookOn('today', 'ABC123');

        $body = $this->body($this->controller->show(
            Request::create('GET', '/appointments/' . $appointment->reference),
            ['reference' => $appointment->reference],
        ));

        self::assertStringContainsString(self::CLINIC_REFERENCE, $body);
        self::assertStringContainsString('No documents are attached to this referral', $body);
        // No consent has been recorded, and the page says so rather than implying one.
        self::assertStringContainsString('No consent discussion is recorded', $body);
    }

    public function testAnUnknownReferenceIsNotFound(): void
    {
        $this->expectException(HttpException::class);

        $this->controller->show(
            Request::create('GET', '/appointments/BK-000000-NOPE00'),
            ['reference' => 'BK-000000-NOPE00'],
        );
    }

    /**
     * A fresh session and a fresh controller: `Gate` resolves the session user once
     * per instance, so reusing one controller across two accounts would leave the
     * first account's authorities in place and test the wrong person.
     */
    private function signIn(string $email): void
    {
        $_SESSION = [];

        $auth = new Auth($this->users, new PasswordHasher());
        $auth->attempt($email, $this->password, '127.0.0.1');

        $gate = new Gate($auth);
        $consents = new InMemoryConsentRepository();
        $transaction = new InlineTransaction();

        $this->controller = new AppointmentController(
            new View(dirname(__DIR__, 2) . '/templates'),
            $gate,
            $this->appointments,
            $this->milestones,
            $this->referrals,
            $consents,
            new InMemoryDocumentRepository(),
            new RecordProcedureMilestone(
                $this->appointments,
                $this->milestones,
                $consents,
                new Validator(),
                $transaction,
                new RecordingAuditTrail(),
            ),
            self::ZONE,
        );
    }

    private function bookOn(string $day, string $suffix, ?string $reference = null): Appointment
    {
        $zone = new DateTimeZone(self::ZONE);
        $starts = new DateTimeImmutable($day . ' 09:00', $zone);
        $ends = $starts->modify('+30 minutes');

        return $this->appointments->book(
            new Appointment(
                id: 0,
                reference: $reference ?? 'BK-261120-' . $suffix,
                referralId: $this->referralId,
                status: AppointmentStatus::Scheduled,
                version: 1,
                startsAtUtc: $starts->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                endsAtUtc: $ends->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                durationMinutes: 30,
                patientName: 'Selamawit Girma',
                patientPhone: '+251911000333',
                createdByUserId: 1,
                createdAtUtc: '2026-11-08 15:00:00',
            ),
            1,
            [$starts->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
            new Actor(1, Role::Staff),
        );
    }

    private function clinicDate(string $day): string
    {
        return (new DateTimeImmutable($day, new DateTimeZone(self::ZONE)))->format('Y-m-d');
    }

    private function referralEntity(): Referral
    {
        return new Referral(
            id: 0,
            reference: self::CLINIC_REFERENCE,
            status: ReferralStatus::ConvertedToAppointment,
            version: 2,
            patientName: 'Selamawit Girma',
            patientPhone: '+251911000333',
            patientDob: '1991-04-12',
            guardianName: null,
            guardianPhone: null,
            guardianRelationship: null,
            procedureCode: 'image_guided_fnac',
            requestedUrgency: 'routine',
            clinicalSummary: 'Right thyroid nodule, 2.4 cm.',
            targetSite: 'Right thyroid lobe',
            referringPhysicianName: 'Dr Hana Bekele',
            referringFacility: 'Yekatit 12 Hospital',
            referringPhysicianPhone: '+251911000111',
            submittedByUserId: 1,
            submittedOnBehalfOf: 'self',
            createdAtUtc: '2026-11-01 08:00:00',
        );
    }

    private function body(Response $response): string
    {
        return $response->body();
    }
}
