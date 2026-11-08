<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\Referral;
use App\Domain\ReferralStatus;
use App\Domain\ReferralTransitionException;
use App\Domain\Role;
use App\Domain\TriageReferral;
use App\Domain\ValidationException;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryReferralRepository;

final class TriageReferralTest extends TestCase
{
    private const NOW = '2026-11-08 11:00:00';

    private InMemoryReferralRepository $repository;

    private TriageReferral $command;

    protected function setUp(): void
    {
        $this->repository = new InMemoryReferralRepository();
        $this->command = new TriageReferral($this->repository, new Validator());
    }

    public function testReceptionReceivesASubmittedReferral(): void
    {
        $referral = $this->submitted();

        $updated = $this->command->execute(
            new Actor(4, Role::Staff),
            $referral->id,
            ReferralStatus::Received,
            null,
            self::NOW,
        );

        self::assertSame(ReferralStatus::Received, $updated->status);

        $history = $this->repository->historyFor($referral->id);
        $last = end($history);
        self::assertIsArray($last);
        self::assertSame('staff', $last['actor_role']);
    }

    public function testReceptionCannotApproveAReferralThroughTriage(): void
    {
        $referral = $this->submitted();

        $this->expectException(AuthorisationException::class);
        $this->expectExceptionMessageMatches('/clinical outcome/i');

        $this->command->execute(
            new Actor(4, Role::Staff),
            $referral->id,
            ReferralStatus::ClinicallyApproved,
            null,
            self::NOW,
        );
    }

    public function testReceptionCannotDeclineOrDeferEither(): void
    {
        $referral = $this->submitted();

        foreach ([ReferralStatus::Declined, ReferralStatus::Deferred, ReferralStatus::AlternativePathway] as $target) {
            try {
                $this->command->execute(new Actor(4, Role::Staff), $referral->id, $target, 'no', self::NOW);
                self::fail('Triage accepted a clinical outcome: ' . $target->value);
            } catch (AuthorisationException) {
                self::assertSame(ReferralStatus::Submitted, $this->reload($referral)->status);
            }
        }
    }

    public function testAPatientCannotTriageTheirOwnReferral(): void
    {
        $referral = $this->submitted();

        $this->expectException(AuthorisationException::class);
        $this->command->execute(
            new Actor(7, Role::Patient),
            $referral->id,
            ReferralStatus::Received,
            null,
            self::NOW,
        );
    }

    public function testTriageCannotSkipFromSubmittedStraightToReview(): void
    {
        $referral = $this->submitted();

        $this->expectException(ReferralTransitionException::class);
        $this->command->execute(
            new Actor(4, Role::Staff),
            $referral->id,
            ReferralStatus::ClinicalReview,
            null,
            self::NOW,
        );
    }

    public function testRequestingMoreInformationWithoutSayingWhatIsMissingIsRefused(): void
    {
        $referral = $this->submitted();
        $staff = new Actor(4, Role::Staff);

        $referral = $this->command->execute($staff, $referral->id, ReferralStatus::Received, null, self::NOW);
        $referral = $this->command->execute(
            $staff,
            $referral->id,
            ReferralStatus::AdministrativeReview,
            null,
            self::NOW,
        );

        $this->expectException(ValidationException::class);
        $this->command->execute($staff, $referral->id, ReferralStatus::AwaitingInformation, '  ', self::NOW);
    }

    public function testRequestingMoreInformationRecordsTheRequestInHistory(): void
    {
        $referral = $this->submitted();
        $staff = new Actor(4, Role::Staff);

        $referral = $this->command->execute($staff, $referral->id, ReferralStatus::Received, null, self::NOW);
        $referral = $this->command->execute(
            $staff,
            $referral->id,
            ReferralStatus::AdministrativeReview,
            null,
            self::NOW,
        );

        $updated = $this->command->execute(
            $staff,
            $referral->id,
            ReferralStatus::AwaitingInformation,
            'The ultrasound report is missing the lesion measurements.',
            self::NOW,
        );

        self::assertSame(ReferralStatus::AwaitingInformation, $updated->status);

        $history = $this->repository->historyFor($referral->id);
        $last = end($history);
        self::assertIsArray($last);
        self::assertStringContainsString('lesion measurements', (string) $last['reason']);
    }

    public function testAnAdministratorMayTriageButStillCannotDecide(): void
    {
        $referral = $this->submitted();
        $admin = new Actor(9, Role::Admin);

        $received = $this->command->execute($admin, $referral->id, ReferralStatus::Received, null, self::NOW);
        self::assertSame(ReferralStatus::Received, $received->status);

        $this->expectException(AuthorisationException::class);
        $this->command->execute($admin, $referral->id, ReferralStatus::ClinicallyApproved, null, self::NOW);
    }

    private function submitted(): Referral
    {
        $referral = new Referral(
            id: 0,
            reference: 'RF-TRIAGE-1',
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

    private function reload(Referral $referral): Referral
    {
        $stored = $this->repository->findById($referral->id);
        self::assertNotNull($stored);

        return $stored;
    }
}
