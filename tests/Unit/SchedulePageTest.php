<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\ScheduleController;
use App\Domain\Actor;
use App\Domain\Appointment;
use App\Domain\AppointmentStatus;
use App\Domain\AvailabilityWindow;
use App\Domain\BookAppointment;
use App\Domain\CancelAppointment;
use App\Domain\ClinicalDecision;
use App\Domain\ClinicalDecisionType;
use App\Domain\Referral;
use App\Domain\ReferralStatus;
use App\Domain\RescheduleAppointment;
use App\Domain\Resource;
use App\Domain\Role;
use App\Domain\ScheduledAppointment;
use App\Domain\ScheduleBoard;
use App\Domain\ScheduleWorkspace;
use App\Http\Request;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\PasswordHasher;
use App\Support\Logger;
use App\Support\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryAppointmentRepository;
use Tests\Support\InMemoryReferralRepository;
use Tests\Support\InMemoryResourceBlockRepository;
use Tests\Support\InMemoryScheduleRepository;
use Tests\Support\InMemoryUserRepository;
use Tests\Support\InMemoryOutboxRepository;
use Tests\Support\InlineTransaction;

/**
 * The two scheduling screens, rendered and posted through the real controller.
 *
 * The important assertion is the negative one: a time the server did not offer is
 * refused even when it is posted by hand, and the board's free count matches what
 * the wizard will actually accept.
 */
final class SchedulePageTest extends TestCase
{
    private const DAY = '2031-06-04';

    private ScheduleController $controller;

    private InMemoryScheduleRepository $schedule;

    private InMemoryAppointmentRepository $appointments;

    private InMemoryReferralRepository $referrals;

    protected function setUp(): void
    {
        $_SESSION = [];

        $users = new InMemoryUserRepository();
        $users->seed('clerk@example.test', 'Clerk', Role::Staff, (new PasswordHasher())->hash('correct horse'));
        $auth = new Auth($users, new PasswordHasher());
        $auth->attempt('clerk@example.test', 'correct horse', '127.0.0.1');

        $this->schedule = new InMemoryScheduleRepository();
        $this->schedule->addResource(new Resource(1, 'suite-1', 'IR Suite 1', 'suite', 'CT', true));
        $this->schedule->setWindows(1, [new AvailabilityWindow(3, '08:00', '10:00')]);

        $this->appointments = new InMemoryAppointmentRepository();
        $this->referrals = new InMemoryReferralRepository();

        $booked = $this->appointments->book(
            new Appointment(
                id: 0,
                reference: 'BK-2031-AAAAAA',
                referralId: 1,
                status: AppointmentStatus::Scheduled,
                version: 1,
                startsAtUtc: self::DAY . ' 06:00:00',
                endsAtUtc: self::DAY . ' 07:00:00',
                durationMinutes: 60,
                patientName: 'Patient One',
                patientPhone: '+251911000001',
                createdByUserId: 7,
                createdAtUtc: '2026-11-09 08:00:00',
            ),
            1,
            [self::DAY . ' 06:00:00', self::DAY . ' 06:15:00', self::DAY . ' 06:30:00', self::DAY . ' 06:45:00'],
            new Actor(7, Role::Staff),
        );

        $this->schedule->addAppointment(new ScheduledAppointment($booked, 1));

        foreach (['06:00:00', '06:15:00', '06:30:00', '06:45:00'] as $time) {
            $this->schedule->occupy(1, self::DAY . ' ' . $time, $booked->id);
        }

        $stored = $this->referrals->create($this->referral(), new Actor(7, Role::Staff), '2026-11-09 08:00:00');

        // BookAppointment needs a standing approval, not merely the status: a
        // referral can read "approved" after its decision was deferred.
        $this->referrals->recordDecision(new ClinicalDecision(
            id: 0,
            referralId: $stored->id,
            type: ClinicalDecisionType::Approved,
            rationale: 'Suitable for biopsy.',
            technique: 'CT guided',
            modality: 'CT',
            sedation: null,
            estimatedDurationMinutes: 60,
            preprocedureConditions: null,
            redirectDestination: null,
            informationRequested: null,
            decidedByUserId: 2,
            decidedAtUtc: '2026-11-09 07:00:00',
            supersedesId: null,
        ));

        $workspace = new ScheduleWorkspace(
            new ScheduleBoard($this->schedule, new InMemoryResourceBlockRepository()),
            $this->referrals,
            $this->appointments,
            new BookAppointment(
                $this->referrals,
                $this->appointments,
                new InlineTransaction(),
                new InMemoryOutboxRepository(),
            ),
            new RescheduleAppointment($this->appointments, new InlineTransaction(), new InMemoryOutboxRepository()),
            new CancelAppointment($this->appointments, new InlineTransaction(), new InMemoryOutboxRepository()),
        );

        $this->controller = new ScheduleController(
            new View(dirname(__DIR__, 2) . '/templates'),
            new Gate($auth),
            new Logger(sys_get_temp_dir() . '/schedule-pages'),
            static fn (): ScheduleWorkspace => $workspace,
            'Africa/Addis_Ababa',
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheBoardListsTheAppointmentAndTheRemainingCapacity(): void
    {
        $response = $this->controller->index(
            Request::create('GET', '/schedule', ['date' => self::DAY, 'duration_minutes' => '60']),
        );

        $body = $response->body();

        self::assertSame(200, $response->status);
        self::assertStringContainsString('IR Suite 1', $body);
        self::assertStringContainsString('BK-2031-AAAAAA', $body);
        // 08:00-10:00 local offers five 60-minute starts; 09:00-10:00 is taken, so
        // the four that remain need the 09:00 quantum, leaving exactly one.
        self::assertStringContainsString('1 slot(s) of 60 minutes still free', $body);
    }

    public function testTheWizardOffersOnlyTimesThatFitBothTheWindowAndTheOccupancy(): void
    {
        $response = $this->controller->create(
            Request::create('GET', '/schedule/book', ['date' => self::DAY, 'duration_minutes' => '60']),
        );

        $body = $response->body();

        self::assertSame(200, $response->status);
        self::assertStringContainsString('name="slot"', $body);
        // The only 60-minute start that fits before the blocked hour is 08:00 local
        // (05:00 UTC); later starts run into the booked 09:00.
        self::assertStringContainsString('value="1|' . self::DAY . ' 05:00:00"', $body);
        self::assertStringNotContainsString('value="1|' . self::DAY . ' 06:00:00"', $body);
    }

    public function testPostingATimeTheServerDidNotOfferIsRefusedAndTheBoardSaysWhy(): void
    {
        $token = Csrf::token();

        $response = $this->controller->store(Request::create(
            'POST',
            '/schedule/book',
            [
                Csrf::FIELD => $token,
                'referral_id' => '1',
                'slot' => '1|' . self::DAY . ' 06:00:00',
                'duration_minutes' => '60',
            ],
        ));

        self::assertSame(303, $response->status);
        self::assertStringContainsString('/schedule/book', (string) $response->header('Location'));
        self::assertSame(1, $this->appointments->count(), 'An appointment was created for a taken slot.');
    }

    public function testPostingAnOfferedTimeBooksIt(): void
    {
        $token = Csrf::token();
        $referral = $this->referrals->approvedForScheduling()[0] ?? null;
        self::assertNotNull($referral);

        $response = $this->controller->store(Request::create(
            'POST',
            '/schedule/book',
            [
                Csrf::FIELD => $token,
                'referral_id' => (string) $referral->id,
                'slot' => '1|' . self::DAY . ' 05:00:00',
                'duration_minutes' => '60',
            ],
        ));

        self::assertSame(303, $response->status);
        self::assertSame(2, $this->appointments->count());

        $booked = $this->appointments->findByReference('BK-2031-AAAAAA');
        self::assertNotNull($booked);
        self::assertSame(AppointmentStatus::Scheduled, $booked->status);
    }

    private function referral(): Referral
    {
        return new Referral(
            id: 0,
            reference: 'RF-2026-0051',
            status: ReferralStatus::ApprovedForScheduling,
            version: 3,
            patientName: 'Patient One',
            patientPhone: '+251911000001',
            patientDob: null,
            guardianName: null,
            guardianPhone: null,
            guardianRelationship: null,
            procedureCode: 'image_guided_biopsy',
            requestedUrgency: 'routine',
            clinicalSummary: 'Summary',
            targetSite: 'Liver',
            referringPhysicianName: null,
            referringFacility: null,
            referringPhysicianPhone: null,
            submittedByUserId: 3,
            submittedOnBehalfOf: 'self',
            createdAtUtc: '2026-11-09 08:00:00',
        );
    }
}
