<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\AcknowledgeUrgentCase;
use App\Domain\Actor;
use App\Domain\EscalateUrgentCase;
use App\Domain\RecordUrgentContactAttempt;
use App\Domain\RegisterUrgentCase;
use App\Domain\Role;
use App\Domain\UrgentCase;
use App\Domain\UrgentCoordination;
use App\Domain\UrgentStatus;
use App\Domain\UrgentTransitionException;
use App\Domain\ValidationException;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryUrgentCaseRepository;

final class UrgentContactAndEscalationTest extends TestCase
{
    private const NOW = '2026-11-08 14:00:00';

    private InMemoryUrgentCaseRepository $repository;

    private RecordUrgentContactAttempt $recordContact;

    private EscalateUrgentCase $escalate;

    private AcknowledgeUrgentCase $acknowledge;

    private RegisterUrgentCase $register;

    protected function setUp(): void
    {
        $this->repository = new InMemoryUrgentCaseRepository();
        $this->register = new RegisterUrgentCase($this->repository, new Validator());
        $this->acknowledge = new AcknowledgeUrgentCase($this->repository);
        $this->recordContact = new RecordUrgentContactAttempt($this->repository);
        $this->escalate = new EscalateUrgentCase($this->repository);
    }

    public function testAContactAttemptIsRecordedWithTheNumberDialled(): void
    {
        $case = $this->acknowledgedCase();

        $updated = $this->recordContact->execute(
            new Actor(4, Role::Staff),
            $case->id,
            [
                'contact_role' => 'patient_family',
                'contact_name' => 'Tesfaye Alemu',
                'contact_phone' => '+251911222444',
                'channel' => 'telephone',
                'outcome' => 'answered',
                'notes' => 'Father confirmed the child has been unwell since yesterday evening.',
            ],
            self::NOW,
        );

        self::assertSame(UrgentCoordination::Contacting, $updated->coordination);

        $attempts = $this->repository->contactsFor($case->id);
        self::assertCount(1, $attempts);
        self::assertSame('+251911222444', $attempts[0]['phone']);
        self::assertSame('answered', $attempts[0]['outcome']);
    }

    public function testATelephoneAttemptWithoutANumberIsRefused(): void
    {
        $case = $this->acknowledgedCase();

        $this->expectException(ValidationException::class);

        $this->recordContact->execute(
            new Actor(4, Role::Staff),
            $case->id,
            ['contact_role' => 'hospital', 'channel' => 'telephone', 'outcome' => 'no_answer'],
            self::NOW,
        );
    }

    public function testAFailedAttemptStillMovesTheCaseIntoContacting(): void
    {
        // A failed call is still work done on the case; the record of trying is the
        // point of WF-06, not the record of succeeding.
        $case = $this->acknowledgedCase();

        $updated = $this->recordContact->execute(
            new Actor(4, Role::Staff),
            $case->id,
            [
                'contact_role' => 'receiving_team',
                'contact_phone' => '+251111551234',
                'channel' => 'telephone',
                'outcome' => 'no_answer',
            ],
            self::NOW,
        );

        self::assertSame(UrgentCoordination::Contacting, $updated->coordination);
        self::assertCount(1, $this->repository->contactsFor($case->id));
    }

    public function testContactCannotBeRecordedBeforeTheCaseIsAcknowledged(): void
    {
        $case = $this->registerCase();

        $this->expectException(UrgentTransitionException::class);
        $this->expectExceptionMessageMatches('/acknowledge the case/i');

        $this->recordContact->execute(
            new Actor(4, Role::Staff),
            $case->id,
            [
                'contact_role' => 'hospital',
                'contact_phone' => '+251111551234',
                'channel' => 'telephone',
                'outcome' => 'no_answer',
            ],
            self::NOW,
        );

        self::assertCount(0, $this->repository->contactsFor($case->id));
    }

    public function testAConnectedOutcomeMustNameThePersonReached(): void
    {
        $case = $this->acknowledgedCase();

        $this->expectException(ValidationException::class);
        $this->recordContact->execute(
            new Actor(4, Role::Staff),
            $case->id,
            [
                'contact_role' => 'receiving_team',
                'contact_phone' => '+251111551234',
                'channel' => 'telephone',
                'outcome' => 'connected',
            ],
            self::NOW,
        );
    }

    public function testEscalationIsRecordedAndMovesTheCase(): void
    {
        $case = $this->acknowledgedCase();

        $updated = $this->escalate->execute(
            new Actor(5, Role::Coordinator),
            $case->id,
            1,
            'No answer from the receiving team after three attempts.',
            null,
            self::NOW,
        );

        self::assertSame(UrgentCoordination::Escalated, $updated->coordination);
        self::assertCount(1, $this->repository->escalationsFor($case->id));
    }

    public function testEscalationRecursWithoutLosingTheEarlierRecord(): void
    {
        $case = $this->acknowledgedCase();
        $coordinator = new Actor(5, Role::Coordinator);

        $this->escalate->execute($coordinator, $case->id, 1, 'First escalation: no answer.', null, self::NOW);

        $second = $this->escalate->execute(
            $coordinator,
            $case->id,
            2,
            'Second escalation: still no answer, anaesthetist not available.',
            null,
            self::NOW,
        );

        self::assertSame(UrgentCoordination::Escalated, $second->coordination);

        $escalations = $this->repository->escalationsFor($case->id);
        self::assertCount(2, $escalations);
        self::assertSame([1, 2], array_column($escalations, 'level'));
    }

    public function testACaseCannotBeEscalatedBeforeItIsAcknowledged(): void
    {
        $case = $this->registerCase();

        $this->expectException(UrgentTransitionException::class);
        $this->escalate->execute(
            new Actor(5, Role::Coordinator),
            $case->id,
            1,
            'Nobody has picked this up yet.',
            null,
            self::NOW,
        );
    }

    public function testAPatientCannotEscalateTheirOwnCase(): void
    {
        $case = $this->acknowledgedCase();

        $this->expectException(UrgentTransitionException::class);
        $this->escalate->execute(
            new Actor(7, Role::Patient),
            $case->id,
            1,
            'We have been waiting for a call back.',
            null,
            self::NOW,
        );
    }

    public function testAShortReasonIsRefused(): void
    {
        $case = $this->acknowledgedCase();

        $this->expectException(ValidationException::class);
        $this->escalate->execute(new Actor(5, Role::Coordinator), $case->id, 1, 'stuck', null, self::NOW);
    }

    private function acknowledgedCase(): UrgentCase
    {
        $case = $this->registerCase();

        return $this->acknowledge->execute(new Actor(4, Role::Staff), $case->id, 'application', null, self::NOW);
    }

    private function registerCase(): UrgentCase
    {
        return $this->register->execute(
            new Actor(3, Role::Physician),
            [
                'patient_name' => 'Bereket Tesfaye',
                'patient_dob' => '2025-09-02',
                'patient_age_months' => '14',
                'guardian_name' => 'Tesfaye Alemu',
                'guardian_phone' => '+251911222444',
                'clinical_summary' => 'Colicky abdominal pain with redcurrant stool; target sign on ultrasound.',
                'imaging_summary' => 'Target sign, 3 cm.',
            ],
            self::NOW,
        );
    }
}
