<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\AdministrativeReferralCommand;
use App\Domain\Actor;
use App\Domain\ReceiveElectiveReferral;
use App\Domain\Referral;
use App\Domain\ReferralStatus;
use App\Domain\ReferralTransitionException;
use App\Domain\RequestReferralInformation;
use App\Domain\Role;
use App\Domain\StartAdministrativeReview;
use App\Domain\StartClinicalReview;
use App\Domain\TriageReferral;
use App\Domain\ValidationException;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryReferralRepository;

/**
 * The named steps exist so a route reads like the workflow; they must not create a
 * second set of rules, so each is checked against the same state table.
 */
final class AdministrativeReferralCommandTest extends TestCase
{
    private const NOW = '2026-11-08 17:00:00';

    private InMemoryReferralRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryReferralRepository();
    }

    public function testEachNamedStepReachesExactlyItsDestination(): void
    {
        $referral = $this->submitted();
        $staff = new Actor(4, Role::Staff);

        $steps = [
            new ReceiveElectiveReferral(new TriageReferral($this->repository, new Validator())),
            new StartAdministrativeReview(new TriageReferral($this->repository, new Validator())),
            new StartClinicalReview(new TriageReferral($this->repository, new Validator())),
        ];

        $referral = $this->advance($steps[0], $staff, $referral, ReferralStatus::Received);
        $referral = $this->advance($steps[1], $staff, $referral, ReferralStatus::AdministrativeReview);
        $this->advance($steps[2], $staff, $referral, ReferralStatus::ClinicalReview);
    }

    public function testANamedStepCannotSkipTheStatesBeforeIt(): void
    {
        $referral = $this->submitted();

        $this->expectException(ReferralTransitionException::class);
        (new StartClinicalReview(new TriageReferral($this->repository, new Validator())))
            ->execute(new Actor(4, Role::Staff), $referral->id, null, self::NOW);
    }

    public function testRequestingInformationPrintsItsRequestInHistory(): void
    {
        $referral = $this->submitted();
        $staff = new Actor(4, Role::Staff);
        $triage = new TriageReferral($this->repository, new Validator());

        $referral = (new ReceiveElectiveReferral($triage))->execute($staff, $referral->id, null, self::NOW);
        $referral = (new StartAdministrativeReview($triage))->execute($staff, $referral->id, null, self::NOW);

        (new RequestReferralInformation($triage))->request(
            $staff,
            $referral->id,
            'The ultrasound report does not state the lesion size.',
            self::NOW,
        );

        $history = $this->repository->historyFor($referral->id);
        $last = end($history);
        self::assertIsArray($last);
        self::assertSame('awaiting_information', $last['to']);
        self::assertStringContainsString('lesion size', (string) $last['reason']);
    }

    public function testRequestingInformationWithoutATextIsRefused(): void
    {
        $referral = $this->submitted();
        $staff = new Actor(4, Role::Staff);
        $triage = new TriageReferral($this->repository, new Validator());

        $referral = (new ReceiveElectiveReferral($triage))->execute($staff, $referral->id, null, self::NOW);
        $referral = (new StartAdministrativeReview($triage))->execute($staff, $referral->id, null, self::NOW);

        $this->expectException(ValidationException::class);
        (new RequestReferralInformation($triage))->request($staff, $referral->id, '   ', self::NOW);
    }

    private function advance(
        AdministrativeReferralCommand $command,
        Actor $actor,
        Referral $referral,
        ReferralStatus $expected,
    ): Referral {
        $updated = $command->execute($actor, $referral->id, null, self::NOW);

        self::assertSame($expected, $updated->status);

        return $updated;
    }

    private function submitted(): Referral
    {
        $referral = new Referral(
            id: 0,
            reference: 'RF-STEP-' . bin2hex(random_bytes(3)),
            status: ReferralStatus::Submitted,
            version: 1,
            patientName: 'Selamawit Girma',
            patientPhone: '+251911000333',
            patientDob: '1991-04-12',
            guardianName: null,
            guardianPhone: null,
            guardianRelationship: null,
            procedureCode: 'fluoroscopic_biopsy',
            requestedUrgency: 'routine',
            clinicalSummary: 'Six weeks of right upper quadrant pain.',
            targetSite: 'Liver, segment VI',
            referringPhysicianName: 'Dr Bekele',
            referringFacility: 'St Paul Referral',
            referringPhysicianPhone: null,
            submittedByUserId: 3,
            submittedOnBehalfOf: 'referrer',
            createdAtUtc: '2026-11-07 08:00:00',
        );

        return $this->repository->create($referral, new Actor(3, Role::Physician), '2026-11-07 08:00:00');
    }
}
