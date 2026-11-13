<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\ConsentNotPermittedException;
use App\Domain\Referral;
use App\Domain\ReferralStatus;
use App\Domain\Role;
use App\Domain\ValidationException;
use App\Domain\VerifyGuardianAuthority;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryReferralRepository;

/**
 * Verifying that a guardian may decide for the patient (PAT-013).
 *
 * The record has to say who checked, when and against what, because the referral
 * naming a guardian is only a claim. The refusals matter as much as the write: a
 * referral with no guardian has no authority to check, a second verification would
 * overwrite the first account, and a verification with no stated basis is the
 * checkbox this exists to avoid.
 */
final class VerifyGuardianAuthorityTest extends TestCase
{
    private const NOW = '2026-11-13 12:00:00';

    private InMemoryReferralRepository $referrals;

    private VerifyGuardianAuthority $command;

    protected function setUp(): void
    {
        $this->referrals = new InMemoryReferralRepository();
        $this->command = new VerifyGuardianAuthority($this->referrals, new Validator());
    }

    public function testStaffRecordWhoCheckedTheAuthorityAndAgainstWhat(): void
    {
        $referral = $this->referral('Meseret Alemu');

        $this->command->execute(
            new Actor(7, Role::Staff),
            $referral->id,
            'Birth certificate and the mother’s identity card, both sighted.',
            self::NOW,
        );

        $verification = $this->referrals->guardianVerification($referral->id);

        self::assertNotNull($verification);
        self::assertSame(self::NOW, $verification['at']);
        self::assertStringContainsString('Birth certificate', $verification['basis']);
    }

    public function testAReferralWithNoGuardianHasNoAuthorityToVerify(): void
    {
        $referral = $this->referral(null);

        $this->expectException(ConsentNotPermittedException::class);

        $this->command->execute(
            new Actor(7, Role::Staff),
            $referral->id,
            'Sighted the mother’s identity card.',
            self::NOW,
        );
    }

    public function testAVerificationWithoutAUsableBasisIsRefused(): void
    {
        $referral = $this->referral('Meseret Alemu');

        try {
            $this->command->execute(new Actor(7, Role::Staff), $referral->id, 'checked', self::NOW);
            self::fail('A verification with a one-word basis was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('guardian_verification_basis', $exception->errors);
        }

        self::assertNull($this->referrals->guardianVerification($referral->id));
    }

    public function testASecondVerificationIsRefusedRatherThanOverwritingTheFirst(): void
    {
        $referral = $this->referral('Meseret Alemu');
        $clerk = new Actor(7, Role::Staff);

        $this->command->execute($clerk, $referral->id, 'Birth certificate sighted.', self::NOW);

        $this->expectException(ConsentNotPermittedException::class);

        $this->command->execute($clerk, $referral->id, 'Checked again the following week.', '2026-11-20 09:00:00');
    }

    public function testAPatientCannotVerifyTheirOwnGuardian(): void
    {
        $referral = $this->referral('Meseret Alemu');

        $this->expectException(AuthorisationException::class);

        $this->command->execute(
            new Actor(9, Role::Patient),
            $referral->id,
            'The guardian is my mother.',
            self::NOW,
        );
    }

    private function referral(?string $guardianName): Referral
    {
        return $this->referrals->create(
            new Referral(
                id: 0,
                reference: 'RF-2026-0400',
                status: ReferralStatus::Submitted,
                version: 1,
                patientName: 'Abebe Bekele',
                patientPhone: '+251911000001',
                patientDob: '2016-04-02',
                guardianName: $guardianName,
                guardianPhone: $guardianName === null ? null : '+251911222444',
                guardianRelationship: $guardianName === null ? null : 'mother',
                procedureCode: 'image_guided_fnac',
                requestedUrgency: 'routine',
                clinicalSummary: 'Palpable left breast mass.',
                targetSite: 'Left breast',
                referringPhysicianName: 'Dr Hana Tesfaye',
                referringFacility: 'Black Lion Hospital',
                referringPhysicianPhone: '+251911000002',
                submittedByUserId: 7,
                submittedOnBehalfOf: 'guardian',
                createdAtUtc: self::NOW,
                referringPhysicianProfileId: null,
            ),
            new Actor(7, Role::Staff),
            self::NOW,
        );
    }
}
