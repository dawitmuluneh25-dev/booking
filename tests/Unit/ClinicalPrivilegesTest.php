<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ClinicalPrivileges;
use App\Domain\PractitionerCredentialStatus;
use App\Domain\PractitionerPrivilegeBasis;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryPractitionerCredentialRepository;

/**
 * The approval-authority rule on its own (AC-009, Decision 6).
 *
 * The command tests prove the gate is wired in; these prove its arithmetic. Dates
 * are the whole substance of it, so each window is exercised from both sides: the
 * day before it opens, the day it closes, and the day after.
 */
final class ClinicalPrivilegesTest extends TestCase
{
    private const DAY = '2026-11-14';

    private InMemoryPractitionerCredentialRepository $credentials;

    private ClinicalPrivileges $privileges;

    protected function setUp(): void
    {
        $this->credentials = new InMemoryPractitionerCredentialRepository();
        $this->privileges = new ClinicalPrivileges($this->credentials);
    }

    public function testAVerifiedPrivilegeAutorisesApprovalOnItsFirstAndLastDay(): void
    {
        $this->credentials->seedApprover(7, 'image_guided_fnac', '2026-11-14', '2026-11-14');

        self::assertTrue($this->privileges->check(7, 'image_guided_fnac', self::DAY)->allowed);
    }

    public function testACredentialThatTakesEffectTomorrowAuthorisesNothingToday(): void
    {
        $this->credentials->seedApprover(7, 'image_guided_fnac', '2026-11-15');

        $check = $this->privileges->check(7, 'image_guided_fnac', self::DAY);

        self::assertFalse($check->allowed);
        self::assertStringContainsString('takes effect on 2026-11-15', (string) $check->reason);
    }

    public function testAVerificationThatExpiredYesterdayRefusesAndNamesTheDate(): void
    {
        $this->credentials->seedApprover(7, 'image_guided_fnac', '2026-01-01', '2026-11-13');

        $check = $this->privileges->check(7, 'image_guided_fnac', self::DAY);

        self::assertFalse($check->allowed);
        self::assertStringContainsString('expired on 2026-11-13', (string) $check->reason);
    }

    public function testAnOpenEndedCredentialNeverExpiresByItself(): void
    {
        $this->credentials->seedApprover(7, 'image_guided_fnac', '2026-01-01');

        $check = $this->privileges->check(7, 'image_guided_fnac', '2031-06-30');

        self::assertTrue($check->allowed);
        self::assertNull($check->expiresOn());
    }

    public function testTheEarlierOfTheTwoExpiriesIsTheOneReported(): void
    {
        // Credential runs to 2027; the privilege under it stops sooner.
        $this->credentials->seedApprover(
            7,
            'image_guided_fnac',
            '2026-01-01',
            '2027-01-01',
            PractitionerCredentialStatus::Verified,
            '2026-12-31',
        );

        $check = $this->privileges->check(7, 'image_guided_fnac', self::DAY);

        self::assertTrue($check->allowed);
        self::assertSame('2026-12-31', $check->expiresOn());
    }

    /** A window that closed a day ago is not a refusal of the register — it is a date. */
    public function testAPrivilegeThatEndedRefusesAndNamesTheDayItEnded(): void
    {
        $this->credentials->seedApprover(
            7,
            'image_guided_fnac',
            '2026-01-01',
            null,
            PractitionerCredentialStatus::Verified,
            '2026-11-13',
        );

        $check = $this->privileges->check(7, 'image_guided_fnac', self::DAY);

        self::assertFalse($check->allowed);
        self::assertStringContainsString('ended on 2026-11-13', (string) $check->reason);
    }

    public function testASuspendedCredentialRefusesEvenWithAnOpenWindow(): void
    {
        $this->credentials->seedApprover(
            7,
            'image_guided_fnac',
            '2026-01-01',
            null,
            PractitionerCredentialStatus::Suspended,
        );

        $check = $this->privileges->check(7, 'image_guided_fnac', self::DAY);

        self::assertFalse($check->allowed);
        self::assertStringContainsString('suspended', (string) $check->reason);
    }

    public function testARevokedCredentialSaysANewVerificationIsNeeded(): void
    {
        $this->credentials->seedApprover(
            7,
            'image_guided_fnac',
            '2026-01-01',
            null,
            PractitionerCredentialStatus::Revoked,
        );

        $check = $this->privileges->check(7, 'image_guided_fnac', self::DAY);

        self::assertFalse($check->allowed);
        self::assertStringContainsString('revoked', (string) $check->reason);
        self::assertStringContainsString('new verification', (string) $check->reason);
    }

    public function testNobodyOnTheRegisterIsRefusedWithTheReasonThatNamesAC009(): void
    {
        $check = $this->privileges->check(7, 'image_guided_fnac', self::DAY);

        self::assertFalse($check->allowed);
        self::assertStringContainsString('No credential verification is recorded', (string) $check->reason);
        self::assertStringContainsString('AC-009', (string) $check->reason);
    }

    /**
     * Delegation is a window like any other: a coverage privilege authorises inside
     * its dates and stops when they end, which is what "temporary" has to mean.
     */
    public function testATemporaryCoveragePrivilegeAuthorisesOnlyInsideItsWindow(): void
    {
        $credential = $this->credentials->seedApprover(7, 'image_guided_fnac', '2026-01-01');

        $this->credentials->grantPrivilege(new \App\Domain\PractitionerPrivilege(
            id: 0,
            credentialId: $credential->id,
            procedureCode: 'image_guided_abscess_drainage',
            basis: PractitionerPrivilegeBasis::TemporaryCoverage,
            delegatedFromUserId: 9,
            supervision: null,
            effectiveFrom: '2026-11-01',
            effectiveTo: '2026-11-30',
            grantedByUserId: 2,
            grantedAtUtc: '2026-10-30 08:00:00',
            note: 'Covering a colleague on leave.',
        ));

        self::assertTrue($this->privileges->check(7, 'image_guided_abscess_drainage', '2026-11-15')->allowed);
        self::assertFalse($this->privileges->check(7, 'image_guided_abscess_drainage', '2026-12-01')->allowed);
    }

    /**
     * A supervision condition is recorded, not interpreted: the rule the decision
     * states is about scope and dates, and what a supervision sentence permits has
     * not been decided by anybody.
     */
    public function testASupervisionConditionDoesNotItselfRefuse(): void
    {
        $credential = $this->credentials->seedApprover(7, 'image_guided_fnac', '2026-01-01');
        $this->credentials->grantPrivilege(new \App\Domain\PractitionerPrivilege(
            id: 0,
            credentialId: $credential->id,
            procedureCode: 'image_guided_core_biopsy',
            basis: PractitionerPrivilegeBasis::Own,
            delegatedFromUserId: null,
            supervision: 'Supervised until the lead signs off three cases.',
            effectiveFrom: '2026-01-01',
            effectiveTo: null,
            grantedByUserId: 2,
            grantedAtUtc: '2026-01-01 08:00:00',
            note: null,
        ));

        self::assertTrue($this->privileges->check(7, 'image_guided_core_biopsy', self::DAY)->allowed);
    }
}
