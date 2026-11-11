<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\ClinicalDecisionType;
use App\Domain\RecordClinicalDecision;
use App\Domain\Referral;
use App\Domain\ReferralNotFoundException;
use App\Domain\ReferralStatus;
use App\Domain\ReferralTransitionException;
use App\Domain\Role;
use App\Domain\Transaction;
use App\Domain\ValidationException;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryReferralRepository;

final class RecordClinicalDecisionTest extends TestCase
{
    private const NOW = '2026-11-08 10:00:00';

    private InMemoryReferralRepository $repository;

    private RecordClinicalDecision $command;

    private int $nextId = 1;

    protected function setUp(): void
    {
        $this->repository = new InMemoryReferralRepository();
        $this->command = new RecordClinicalDecision(
            $this->repository,
            new Validator(),
            $this->passthroughTransaction(),
        );
    }

    public function testReceptionCannotApproveEvenIfItReachesTheCommand(): void
    {
        $referral = $this->referralInReview();

        $this->expectException(AuthorisationException::class);
        $this->command->execute(
            new Actor(4, Role::Staff),
            $referral->id,
            $this->approval(),
            self::NOW,
        );
    }

    public function testAnAdministratorCannotApprove(): void
    {
        $referral = $this->referralInReview();

        $this->expectException(AuthorisationException::class);
        $this->command->execute(
            new Actor(9, Role::Admin),
            $referral->id,
            $this->approval(),
            self::NOW,
        );
    }

    public function testTheReferringPhysicianWhoSubmittedItCannotApprove(): void
    {
        $referral = $this->referralInReview();

        $this->expectException(AuthorisationException::class);
        $this->command->execute(
            new Actor(3, Role::Physician),
            $referral->id,
            $this->approval(),
            self::NOW,
        );
    }

    public function testARadiologistApprovalMovesTheReferralAndRecordsOneDecision(): void
    {
        $referral = $this->referralInReview();

        $updated = $this->command->execute(
            new Actor(2, Role::Radiologist),
            $referral->id,
            $this->approval(),
            self::NOW,
        );

        self::assertSame(ReferralStatus::ClinicallyApproved, $updated->status);
        self::assertSame($referral->version + 1, $updated->version);

        $decision = $this->repository->currentDecision($referral->id);
        self::assertNotNull($decision);
        self::assertSame(ClinicalDecisionType::Approved, $decision->type);
        self::assertSame(2, $decision->decidedByUserId);
        self::assertNull($decision->supersedesId);
    }

    public function testApprovalIsRefusedOutsideClinicalReview(): void
    {
        // A referral still in triage must be reviewed, not approved in passing.
        $referral = $this->referral(ReferralStatus::AdministrativeReview);

        $this->expectException(ReferralTransitionException::class);
        $this->command->execute(
            new Actor(2, Role::Radiologist),
            $referral->id,
            $this->approval(),
            self::NOW,
        );
    }

    public function testADeclineRequiresAReasonAndMovesToDeclined(): void
    {
        $referral = $this->referralInReview();

        $updated = $this->command->execute(
            new Actor(2, Role::Radiologist),
            $referral->id,
            [
                'decision_type' => 'declined',
                'rationale' => 'Lesion is too small to characterise safely; repeat imaging in six months.',
            ],
            self::NOW,
        );

        self::assertSame(ReferralStatus::Declined, $updated->status);

        $history = $this->repository->historyFor($referral->id);
        $last = end($history);
        self::assertIsArray($last);
        self::assertSame('declined', $last['to']);
        self::assertStringContainsString('too small', (string) $last['reason']);
    }

    public function testRedirectingWithoutADestinationIsRefused(): void
    {
        $referral = $this->referralInReview();

        $this->expectException(ValidationException::class);
        $this->command->execute(
            new Actor(2, Role::Radiologist),
            $referral->id,
            [
                'decision_type' => 'redirected',
                'rationale' => 'Needs surgical assessment rather than image-guided intervention.',
            ],
            self::NOW,
        );
    }

    public function testARedirectWithADestinationMovesToAlternativePathway(): void
    {
        $referral = $this->referralInReview();

        $updated = $this->command->execute(
            new Actor(2, Role::Radiologist),
            $referral->id,
            [
                'decision_type' => 'redirected',
                'rationale' => 'Needs surgical assessment rather than image-guided intervention.',
                'redirect_destination' => 'General surgery clinic',
            ],
            self::NOW,
        );

        self::assertSame(ReferralStatus::AlternativePathway, $updated->status);
    }

    public function testAnUnknownDecisionTypeIsRefused(): void
    {
        $referral = $this->referralInReview();

        $this->expectException(ValidationException::class);
        $this->command->execute(
            new Actor(2, Role::Radiologist),
            $referral->id,
            ['decision_type' => 'rubber_stamp', 'rationale' => 'Approved without reading it.'],
            self::NOW,
        );
    }

    public function testASecondDecisionSupersedesTheFirstRatherThanReplacingIt(): void
    {
        $referral = $this->referralInReview();
        $radiologist = new Actor(2, Role::Radiologist);

        // Defer, then bring the referral back into review — the only path the state
        // table allows from a decided referral — and decide again.
        $this->command->execute(
            $radiologist,
            $referral->id,
            ['decision_type' => 'deferred', 'rationale' => 'Anaesthetic review needed before booking.'],
            self::NOW,
        );
        $first = $this->repository->currentDecision($referral->id);
        self::assertNotNull($first);
        self::assertSame(ClinicalDecisionType::Deferred, $first->type);

        $reloaded = $this->repository->findById($referral->id);
        self::assertNotNull($reloaded);

        $this->repository->transition(
            $reloaded,
            ReferralStatus::ClinicalReview,
            new Actor(4, Role::Staff),
            null,
            self::NOW,
        );

        $this->command->execute($radiologist, $referral->id, $this->approval(), self::NOW);

        $standing = $this->repository->currentDecision($referral->id);
        self::assertNotNull($standing);
        self::assertSame(ClinicalDecisionType::Approved, $standing->type);
        self::assertSame($first->id, $standing->supersedesId);
        self::assertNotSame($first->id, $standing->id);
    }

    public function testAMissingReferralIsNotFoundRatherThanSilentlyCreated(): void
    {
        $this->expectException(ReferralNotFoundException::class);
        $this->command->execute(new Actor(2, Role::Radiologist), 999, $this->approval(), self::NOW);
    }

    /**
     * §13.1 makes the category a clinical decision with a rationale, and §12.4
     * says a review is not complete until it is recorded. Approval is also the one
     * outcome whose whole effect is to release the referral into a queue, so an
     * approval that names no category leaves the queue with nothing to order it by.
     */
    public function testAnApprovalWithoutAClinicalPriorityIsRefused(): void
    {
        $referral = $this->referralInReview();
        $approval = $this->approval();
        unset($approval['clinical_priority']);

        try {
            $this->command->execute(new Actor(2, Role::Radiologist), $referral->id, $approval, self::NOW);
            self::fail('An approval was recorded without stating a clinical priority.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('clinical_priority', $exception->errors);
        }

        // Nothing was written: the referral is still awaiting review and no
        // decision row exists to be read later as if it had been approved.
        self::assertSame(ReferralStatus::ClinicalReview, $this->statusOf($referral->id));
        self::assertNull($this->repository->currentDecision($referral->id));
    }

    public function testAnApprovalRecordsTheCategoryOnBothTheDecisionAndTheReferral(): void
    {
        $referral = $this->referralInReview();

        $updated = $this->command->execute(
            new Actor(2, Role::Radiologist),
            $referral->id,
            ['clinical_priority' => 'expedited'] + $this->approval(),
            self::NOW,
        );

        self::assertSame('expedited', $updated->clinicalPriority);
        self::assertSame('Expedited', $updated->clinicalPriorityLabel());

        $decision = $this->repository->currentDecision($referral->id);
        self::assertNotNull($decision);
        // The decision keeps the category it assigned, so "was this expedited when
        // it was approved" is answered by the approving row and not by the newest
        // value anyone happened to write afterwards (§12.1).
        self::assertSame('expedited', $decision->clinicalPriority);
    }

    /**
     * Urgent direct coordination exists to say the ordinary queue is the wrong
     * route (§13.1). An approval is what puts a referral into that queue, so the
     * combination is refused rather than recorded as an instruction the next
     * screen will ignore.
     */
    public function testAnApprovalCannotLeaveTheOrdinaryQueue(): void
    {
        $referral = $this->referralInReview();

        try {
            $this->command->execute(
                new Actor(2, Role::Radiologist),
                $referral->id,
                ['clinical_priority' => 'urgent_direct_coordination'] + $this->approval(),
                self::NOW,
            );
            self::fail('Urgent direct coordination was recorded as an elective approval.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('Redirect', (string) ($exception->errors['clinical_priority'] ?? ''));
        }

        self::assertSame(ReferralStatus::ClinicalReview, $this->statusOf($referral->id));
    }

    /**
     * A redirect promises no booking and does not return the referral to a queue,
     * which is exactly what "speak to the receiving team instead" needs. It is the
     * only elective outcome where the third category can be recorded.
     */
    public function testARedirectCanRecordUrgentDirectCoordination(): void
    {
        $referral = $this->referralInReview();

        $updated = $this->command->execute(
            new Actor(2, Role::Radiologist),
            $referral->id,
            [
                'decision_type' => 'redirected',
                'rationale' => 'Needs same-day surgical review; the team must be spoken to directly.',
                'redirect_destination' => 'General surgery on-call team',
                'clinical_priority' => 'urgent_direct_coordination',
            ],
            self::NOW,
        );

        self::assertSame(ReferralStatus::AlternativePathway, $updated->status);
        self::assertSame('urgent_direct_coordination', $updated->clinicalPriority);
        self::assertTrue($updated->requiresDirectCoordination());
    }

    /** An outcome that releases nothing has no queue to order, so it stores no priority. */
    public function testAnOutcomeThatReleasesNothingRefusesAClinicalPriority(): void
    {
        $referral = $this->referralInReview();

        try {
            $this->command->execute(
                new Actor(2, Role::Radiologist),
                $referral->id,
                [
                    'decision_type' => 'declined',
                    'rationale' => 'Lesion is too small to characterise safely; repeat imaging in six months.',
                    'clinical_priority' => 'expedited',
                ],
                self::NOW,
            );
            self::fail('A declined referral kept a priority that nothing can act on.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('clinical_priority', $exception->errors);
        }

        self::assertNull($this->repository->currentDecision($referral->id));
    }

    public function testAnUnknownClinicalPriorityIsRefusedByName(): void
    {
        $referral = $this->referralInReview();

        try {
            $this->command->execute(
                new Actor(2, Role::Radiologist),
                $referral->id,
                ['clinical_priority' => 'urgent'] + $this->approval(),
                self::NOW,
            );
            self::fail('The retired word was accepted as a clinical priority.');
        } catch (ValidationException $exception) {
            $message = (string) ($exception->errors['clinical_priority'] ?? '');

            self::assertStringContainsString('Urgent direct coordination', $message);
            self::assertStringNotContainsString('in:', $message);
        }
    }

    /**
     * AC-010: the submitter's request and the clinician's assignment are two
     * facts, and neither overwrites the other.
     */
    public function testARequestedUrgencyDoesNotBecomeTheClinicalPriority(): void
    {
        $referral = $this->referral(ReferralStatus::ClinicalReview, requestedUrgency: 'expedited');

        $updated = $this->command->execute(
            new Actor(2, Role::Radiologist),
            $referral->id,
            ['clinical_priority' => 'routine'] + $this->approval(),
            self::NOW,
        );

        self::assertSame('expedited', $updated->requestedUrgency);
        self::assertSame('routine', $updated->clinicalPriority);
    }

    /** @return array<string, string> */
    private function approval(): array
    {
        return [
            'decision_type' => 'approved',
            'rationale' => 'Two centimetre segment VI lesion, safe window, suitable for percutaneous biopsy.',
            'clinical_priority' => 'routine',
            'approved_technique' => 'Percutaneous core biopsy',
            'modality' => 'Ultrasound',
            'sedation' => 'Local anaesthetic',
            'estimated_duration_minutes' => '45',
        ];
    }

    private function referralInReview(): Referral
    {
        return $this->referral(ReferralStatus::ClinicalReview);
    }

    private function statusOf(int $referralId): ReferralStatus
    {
        $stored = $this->repository->findById($referralId);
        self::assertInstanceOf(Referral::class, $stored);

        return $stored->status;
    }

    private function referral(ReferralStatus $status, string $requestedUrgency = 'routine'): Referral
    {
        $referral = new Referral(
            id: 0,
            reference: 'RF-TEST-' . $this->nextId++,
            status: $status,
            version: 1,
            patientName: 'Selamawit Girma',
            patientPhone: '+251911000333',
            patientDob: '1991-04-12',
            guardianName: null,
            guardianPhone: null,
            guardianRelationship: null,
            procedureCode: 'image_guided_biopsy',
            requestedUrgency: $requestedUrgency,
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

    /** The unit of work is irrelevant to a command test with an in-memory store. */
    private function passthroughTransaction(): Transaction
    {
        return new class implements Transaction {
            public function run(callable $work): mixed
            {
                return $work();
            }

            public function depth(): int
            {
                return 0;
            }
        };
    }
}
