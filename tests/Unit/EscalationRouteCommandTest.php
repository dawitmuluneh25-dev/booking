<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\EscalationReadiness;
use App\Domain\EscalationRouteKind;
use App\Domain\RecordEscalationRoute;
use App\Domain\RetireEscalationRoute;
use App\Domain\Role;
use App\Domain\ValidationException;
use App\Domain\VerifyEscalationRoute;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryEscalationRouteRepository;
use Tests\Support\InlineTransaction;
use Tests\Support\RecordingAuditTrail;

/**
 * The escalation roster's rules (Decision 4).
 *
 * Two facts are kept apart throughout: a route exists, and a route is verified. The
 * tests are mostly about that gap — what counts, what does not, and what happens when
 * a number nobody answers is the only one on the list.
 */
final class EscalationRouteCommandTest extends TestCase
{
    private const NOW = '2026-11-14 10:00:00';

    private InMemoryEscalationRouteRepository $routes;

    private RecordEscalationRoute $record;

    private VerifyEscalationRoute $verify;

    private RetireEscalationRoute $retire;

    private RecordingAuditTrail $audit;

    protected function setUp(): void
    {
        $this->routes = new InMemoryEscalationRouteRepository();
        $this->audit = new RecordingAuditTrail();
        $transaction = new InlineTransaction();

        $this->record = new RecordEscalationRoute($this->routes, new Validator(), $transaction, $this->audit);
        $this->verify = new VerifyEscalationRoute($this->routes, $transaction, $this->audit);
        $this->retire = new RetireEscalationRoute($this->routes, $transaction, $this->audit);
    }

    public function testRecordingIsNotVerifying(): void
    {
        $route = $this->record->execute(new Actor(1, Role::Admin), $this->input(), self::NOW);

        self::assertGreaterThan(0, $route->id);
        self::assertFalse($route->isVerified());
        self::assertNull($route->verifiedAtUtc);
        self::assertFalse($route->countsTowardActivation());

        // A recorded but unverified primary route leaves escalation disabled.
        self::assertFalse((new EscalationReadiness($this->routes))->assess()->activated);
    }

    public function testAnAfterHoursRouteThatIsNotStaffedCannotEvenBeRecorded(): void
    {
        try {
            $this->record->execute(
                new Actor(1, Role::Admin),
                ['kind' => 'after_hours', 'is_staffed' => '0'] + $this->input(),
            );
            self::fail('An unstaffed after-hours route was recorded.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('is_staffed', $exception->errors);
        }

        self::assertSame([], $this->routes->all());
    }

    public function testTheDeskCannotConfigureEscalation(): void
    {
        $this->expectException(AuthorisationException::class);

        $this->record->execute(new Actor(9, Role::Staff), $this->input());
    }

    public function testAVerificationHasToSayHowItWasChecked(): void
    {
        $route = $this->record->execute(new Actor(1, Role::Admin), $this->input(), self::NOW);

        try {
            $this->verify->execute(new Actor(1, Role::Admin), $route->id, 'ok');
            self::fail('A verification with no account of how was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('verification_note', $exception->errors);
        }

        $stored = $this->routes->find($route->id);
        self::assertNotNull($stored);
        self::assertFalse($stored->isVerified());
    }

    public function testVerifyingRecordsWhoCheckedItAndWhen(): void
    {
        $route = $this->record->execute(new Actor(1, Role::Admin), $this->input(), self::NOW);

        $verified = $this->verify->execute(
            new Actor(1, Role::Admin),
            $route->id,
            'Rang the switchboard; the duty radiologist answered and confirmed the rota.',
            self::NOW,
        );

        self::assertTrue($verified->isVerified());
        self::assertSame(1, $verified->verifiedByUserId);
        self::assertSame(self::NOW, $verified->verifiedAtUtc);
        self::assertTrue($verified->countsTowardActivation());
    }

    public function testTheThreeRequiredRoutesActivateEscalationAndAfterHoursDoesNot(): void
    {
        $this->seedVerified(EscalationRouteKind::Primary);
        $this->seedVerified(EscalationRouteKind::Secondary);
        $readiness = new EscalationReadiness($this->routes);

        self::assertFalse($readiness->assess()->activated);
        self::assertSame([EscalationRouteKind::ReceivingTeam], $readiness->assess()->missing);

        $this->seedVerified(EscalationRouteKind::ReceivingTeam);

        self::assertTrue($readiness->assess()->activated);
        self::assertSame([], $readiness->assess()->missing);
    }

    public function testTheTechnicalFallbackNeverActivatesEscalationOnItsOwn(): void
    {
        $this->seedVerified(EscalationRouteKind::TechnicalFallback);
        $this->seedVerified(EscalationRouteKind::AfterHours);

        $report = (new EscalationReadiness($this->routes))->assess();

        self::assertFalse($report->activated);
        self::assertCount(3, $report->missing);
        self::assertStringContainsString('no verified primary contact', $report->message());
    }

    public function testRetiringARouteTakesItOutOfTheCountButKeepsTheRow(): void
    {
        $route = $this->seedVerified(EscalationRouteKind::Primary);
        $this->seedVerified(EscalationRouteKind::Secondary);
        $this->seedVerified(EscalationRouteKind::ReceivingTeam);

        $readiness = new EscalationReadiness($this->routes);
        self::assertTrue($readiness->assess()->activated);

        $this->retire->execute(new Actor(1, Role::Admin), $route->id, self::NOW);

        self::assertFalse($readiness->assess()->activated);
        $stored = $this->routes->find($route->id);
        self::assertNotNull($stored);
        self::assertFalse($stored->isActive);
        // The verification survives retirement: a case escalated through it last
        // month still has to explain where it went.
        self::assertTrue($stored->isVerified());
    }

    public function testARetiredRouteCannotBeVerifiedBackIntoService(): void
    {
        $route = $this->seedVerified(EscalationRouteKind::Primary);
        $this->retire->execute(new Actor(1, Role::Admin), $route->id, self::NOW);

        $this->expectException(ValidationException::class);

        $this->verify->execute(new Actor(1, Role::Admin), $route->id, 'Called again and it answered.');
    }

    private function seedVerified(EscalationRouteKind $kind): \App\Domain\EscalationRoute
    {
        $route = $this->record->execute(
            new Actor(1, Role::Admin),
            ['kind' => $kind->value] + $this->input(),
            self::NOW,
        );

        return $this->verify->execute(
            new Actor(1, Role::Admin),
            $route->id,
            'Rang the number and the named person answered.',
            self::NOW,
        );
    }

    /** @return array<string, string> */
    private function input(): array
    {
        return [
            'kind' => 'primary',
            'label' => 'On-call radiologist',
            'contact_name' => 'Duty radiologist',
            'contact_phone' => '+251911000333',
            'is_staffed' => '1',
        ];
    }
}
