<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\AccountRequestStatus;
use App\Domain\AccountRequestThrottle;
use App\Domain\AccountRequestThrottledException;
use App\Domain\AuditAction;
use App\Domain\RequestAccount;
use App\Domain\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedHumanCheck;
use Tests\Support\InMemoryAccountRequestAttempts;
use Tests\Support\InMemoryAccountRequestRepository;
use Tests\Support\RecordingAuditTrail;

/**
 * The submission rules for the public account-request form (ADR-013).
 *
 * The assertion that matters most is the negative one: every refusal path must leave
 * the table empty and, where a stranger can see it, answer exactly like success.
 */
final class RequestAccountTest extends TestCase
{
    private const NOW = '2026-11-18 09:00:00';

    private const ADDRESS = '203.0.113.9';

    private InMemoryAccountRequestRepository $requests;

    private InMemoryAccountRequestAttempts $attempts;

    private RecordingAuditTrail $audit;

    protected function setUp(): void
    {
        $this->requests = new InMemoryAccountRequestRepository();
        $this->attempts = new InMemoryAccountRequestAttempts();
        $this->audit = new RecordingAuditTrail();
    }

    public function testItStoresAPendingRequestAndReturnsAReference(): void
    {
        $reference = $this->command()->execute($this->payload(), self::ADDRESS, self::NOW);

        self::assertSame(1, $this->requests->countPending());

        $stored = $this->requests->findById(1);
        self::assertNotNull($stored);
        self::assertSame($reference, $stored->reference);
        self::assertSame(AccountRequestStatus::Pending, $stored->status);
        self::assertSame('Dr. Selam Bekele', $stored->fullName);
        self::assertSame('selam@example.test', $stored->email);
    }

    public function testTheReferenceAvoidsCharactersThatAreMisreadAloud(): void
    {
        $reference = $this->command()->execute($this->payload(), self::ADDRESS, self::NOW);

        self::assertMatchesRegularExpression('/^AR-[ACDEFGHJKLMNPQRTUVWXY34679]{7}$/', $reference);
        self::assertStringNotContainsString('O', $reference);
        self::assertStringNotContainsString('0', $reference);
        self::assertStringNotContainsString('I', $reference);
        self::assertStringNotContainsString('1', $reference);
    }

    public function testAFilledHoneypotWritesNothingAndStillAnswersLikeSuccess(): void
    {
        $payload = $this->payload();
        $payload['website'] = 'https://spam.example';

        $reference = $this->command()->execute($payload, self::ADDRESS, self::NOW);

        self::assertMatchesRegularExpression('/^AR-[ACDEFGHJKLMNPQRTUVWXY34679]{7}$/', $reference);
        self::assertSame(0, $this->requests->countPending());
        self::assertSame([], $this->audit->entries());
    }

    public function testAWrongChallengeAnswerIsAFormErrorAndWritesNothing(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->command(false)->execute($this->payload(), self::ADDRESS, self::NOW);
        } finally {
            self::assertSame(0, $this->requests->countPending());
        }
    }

    public function testAnInvalidAddressIsRefusedWithoutStoringAnything(): void
    {
        $payload = $this->payload();
        $payload['email'] = 'not-an-address';

        try {
            $this->command()->execute($payload, self::ADDRESS, self::NOW);
            self::fail('An invalid address should not be accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('email', $exception->errors);
        }

        self::assertSame(0, $this->requests->countPending());
    }

    public function testTheSamePersonAndPracticeInsideTheWindowIsOneRequest(): void
    {
        $first = $this->command()->execute($this->payload(), self::ADDRESS, self::NOW);

        $second = $this->command()->execute(
            $this->payload(),
            self::ADDRESS,
            '2026-11-18 15:00:00',
        );

        self::assertSame($first, $second);
        self::assertSame(1, $this->requests->countPending());
    }

    public function testTheSamePersonAtADifferentPracticeIsASecondRequest(): void
    {
        $this->command()->execute($this->payload(), self::ADDRESS, self::NOW);

        $payload = $this->payload();
        $payload['practice_name'] = 'Bethel Referral Clinic';

        $reference = $this->command()->execute($payload, self::ADDRESS, '2026-11-18 09:30:00');

        self::assertNotSame('', $reference);
        self::assertSame(2, $this->requests->countPending());
    }

    public function testItRefusesOnceTheNetworkHasUsedItsHourlyAllowance(): void
    {
        // Five admitted submissions, each for a different practice so the duplicate
        // rule cannot mask the cap.
        for ($i = 1; $i <= AccountRequestThrottle::PER_HOUR; $i++) {
            $payload = $this->payload();
            $payload['practice_name'] = 'Practice ' . $i;

            $this->command()->execute($payload, self::ADDRESS, self::NOW);
        }

        $this->expectException(AccountRequestThrottledException::class);

        $this->command()->execute($this->payload(), self::ADDRESS, self::NOW);
    }

    public function testAnHourLaterTheAllowanceIsAvailableAgain(): void
    {
        for ($i = 1; $i <= AccountRequestThrottle::PER_HOUR; $i++) {
            $payload = $this->payload();
            $payload['practice_name'] = 'Practice ' . $i;

            $this->command()->execute($payload, self::ADDRESS, self::NOW);
        }

        $payload = $this->payload();
        $payload['practice_name'] = 'Practice after the hour';

        $reference = $this->command()->execute($payload, self::ADDRESS, '2026-11-18 10:30:00');

        self::assertMatchesRegularExpression('/^AR-/', $reference);
    }

    public function testTheSubmissionIsAuditedWithoutTheStrangersFreeText(): void
    {
        $this->command()->execute($this->payload(), self::ADDRESS, self::NOW);

        $entries = $this->audit->entries();
        self::assertCount(1, $entries);
        self::assertSame(AuditAction::AccountRequested, $entries[0]->action);
        self::assertNull($entries[0]->actorUserId);
        self::assertNotNull($entries[0]->after);
        self::assertArrayNotHasKey('note', $entries[0]->after);
        self::assertArrayNotHasKey('email', $entries[0]->after);
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return [
            'full_name' => 'Dr. Selam Bekele',
            'email' => 'selam@example.test',
            'practice_name' => 'Kirkos Medical Centre',
            'practice_city' => 'Addis Ababa',
            'registration_no' => 'ET-MD-4471',
            'note' => 'I refer paediatric cases weekly.',
            'website' => '',
            'check_token' => 'token',
            'check_answer' => '11',
        ];
    }

    private function command(bool $human = true): RequestAccount
    {
        return new RequestAccount(
            $this->requests,
            new AccountRequestThrottle($this->attempts),
            new FixedHumanCheck($human),
            $this->audit,
        );
    }
}
