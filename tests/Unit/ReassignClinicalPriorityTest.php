<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\Permission;
use App\Domain\ReassignClinicalPriority;
use App\Domain\Referral;
use App\Domain\ReferralNotFoundException;
use App\Domain\ReferralStatus;
use App\Domain\Role;
use App\Domain\ValidationException;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InlineTransaction;
use Tests\Support\InMemoryReferralRepository;

/**
 * Changing a clinical priority that has already been assigned (§13.1).
 *
 * The point of the slice is that the earlier category survives the change, and that
 * the four things this command cannot honestly do are refused rather than recorded.
 */
final class ReassignClinicalPriorityTest extends TestCase
{
    private const NOW = '2026-11-10 09:00:00';

    private InMemoryReferralRepository $referrals;

    private ReassignClinicalPriority $command;

    private Actor $radiologist;

    protected function setUp(): void
    {
        $this->referrals = new InMemoryReferralRepository([1 => 'Dr Selam Bekele']);
        $this->command = new ReassignClinicalPriority($this->referrals, new Validator(), new InlineTransaction());
        $this->radiologist = new Actor(1, Role::Radiologist);
    }

    public function testExpeditingAReferralKeepsTheEarlierCategoryOnTheRecord(): void
    {
        $referral = $this->approved('routine', ReferralStatus::ApprovedForScheduling);

        $moved = $this->command->execute($this->radiologist, $referral->id, [
            'clinical_priority' => 'expedited',
            'reason' => 'The repeat film shows progression, so this should not wait for the next routine list.',
        ], self::NOW);

        self::assertSame('expedited', $moved->clinicalPriority);

        $history = $this->referrals->priorityChangeHistory($referral->id);

        self::assertCount(1, $history);
        self::assertSame('routine', $history[0]['change']->fromPriority);
        self::assertSame('expedited', $history[0]['change']->toPriority);
        self::assertSame(1, $history[0]['change']->changedByUserId);
        self::assertSame('Dr Selam Bekele', $history[0]['changedBy']);
    }

    public function testAReferralWithNoCategoryHasNothingToChange(): void
    {
        $referral = $this->approved(null, ReferralStatus::ApprovedForScheduling);

        $this->expectException(ValidationException::class);

        try {
            $this->command->execute($this->radiologist, $referral->id, [
                'clinical_priority' => 'expedited',
                'reason' => 'Escalating a referral that never had a category assigned.',
            ], self::NOW);
        } catch (ValidationException $exception) {
            self::assertStringContainsString(
                'assigned with the decision',
                (string) ($exception->errors['clinical_priority'] ?? ''),
            );
            self::assertSame([], $this->referrals->priorityChangeHistory($referral->id));

            throw $exception;
        }
    }

    public function testTheSameCategoryIsRefusedRatherThanStored(): void
    {
        $referral = $this->approved('expedited', ReferralStatus::ClinicallyApproved);

        $this->expectException(ValidationException::class);

        try {
            $this->command->execute($this->radiologist, $referral->id, [
                'clinical_priority' => 'expedited',
                'reason' => 'Re-stating the category that is already standing on the referral.',
            ], self::NOW);
        } catch (ValidationException $exception) {
            self::assertStringContainsString(
                'nothing to change',
                (string) ($exception->errors['clinical_priority'] ?? ''),
            );
            throw $exception;
        }
    }

    public function testUrgentDirectCoordinationIsRefusedBecauseItIsNotAQueuePosition(): void
    {
        $referral = $this->approved('routine', ReferralStatus::ApprovedForScheduling);

        $this->expectException(ValidationException::class);

        try {
            $this->command->execute($this->radiologist, $referral->id, [
                'clinical_priority' => 'urgent_direct_coordination',
                'reason' => 'This has become an urgent case and should leave the routine queue.',
            ], self::NOW);
        } catch (ValidationException $exception) {
            self::assertStringContainsString(
                'wrong route',
                (string) ($exception->errors['clinical_priority'] ?? ''),
            );
            self::assertSame('routine', $this->referrals->findById($referral->id)?->clinicalPriority);

            throw $exception;
        }
    }

    public function testAReferralWithAnAppointmentIsRefusedBecauseRebookingIsNotThisCommand(): void
    {
        $referral = $this->approved('routine', ReferralStatus::ConvertedToAppointment);

        $this->expectException(ValidationException::class);

        try {
            $this->command->execute($this->radiologist, $referral->id, [
                'clinical_priority' => 'expedited',
                'reason' => 'The date is too far out and the imaging has changed since.',
            ], self::NOW);
        } catch (ValidationException $exception) {
            self::assertStringContainsString(
                'scheduling decision',
                (string) ($exception->errors['clinical_priority'] ?? ''),
            );
            throw $exception;
        }
    }

    public function testAReferralStillInClinicalReviewCannotBeReassigned(): void
    {
        $referral = $this->approved('routine', ReferralStatus::ClinicalReview);

        $this->expectException(ValidationException::class);

        try {
            $this->command->execute($this->radiologist, $referral->id, [
                'clinical_priority' => 'expedited',
                'reason' => 'Trying to change a category before the referral leaves review.',
            ], self::NOW);
        } catch (ValidationException $exception) {
            self::assertStringContainsString(
                'approved for scheduling',
                (string) ($exception->errors['clinical_priority'] ?? ''),
            );
            throw $exception;
        }
    }

    public function testAShortReasonIsRefused(): void
    {
        $referral = $this->approved('routine', ReferralStatus::ApprovedForScheduling);

        $this->expectException(ValidationException::class);

        $this->command->execute($this->radiologist, $referral->id, [
            'clinical_priority' => 'expedited',
            'reason' => 'urgent',
        ], self::NOW);
    }

    public function testAReceptionistMayNotChangeAClinicalPriority(): void
    {
        $referral = $this->approved('routine', ReferralStatus::ApprovedForScheduling);

        $this->expectException(AuthorisationException::class);
        $this->expectExceptionMessage(Permission::ClinicalApprove->value);

        $this->command->execute(new Actor(2, Role::Staff), $referral->id, [
            'clinical_priority' => 'expedited',
            'reason' => 'Reception moves a referral up the queue without a clinician.',
        ], self::NOW);
    }

    public function testAMissingReferralIsRefusedByItsIdentifier(): void
    {
        $this->expectException(ReferralNotFoundException::class);

        $this->command->execute($this->radiologist, 999, [
            'clinical_priority' => 'expedited',
            'reason' => 'This referral does not exist, which is the point of the test.',
        ], self::NOW);
    }

    private function approved(?string $priority, ReferralStatus $status): Referral
    {
        $referral = new Referral(
            id: 0,
            reference: 'REF-TEST-' . bin2hex(random_bytes(3)),
            status: $status,
            version: 1,
            patientName: 'Bereket Tesfaye',
            patientPhone: '+251911222444',
            patientDob: '2021-03-04',
            guardianName: 'Almaz Tesfaye',
            guardianPhone: '+251911222444',
            guardianRelationship: 'Mother',
            procedureCode: 'image_guided_fnac',
            requestedUrgency: 'routine',
            clinicalSummary: 'A 2 cm neck node, persistent for six weeks.',
            targetSite: 'Left cervical level II',
            referringPhysicianName: 'Dr Yonas Girma',
            referringFacility: 'Adama Hospital',
            referringPhysicianPhone: '+251911222000',
            submittedByUserId: 3,
            submittedOnBehalfOf: 'self',
            createdAtUtc: self::NOW,
            clinicalPriority: $priority,
        );

        return $this->referrals->create($referral, new Actor(3, Role::Staff), self::NOW);
    }
}
