<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\Referral;
use App\Domain\ReferralStatus;
use App\Domain\ReferralTrackingLink;
use App\Domain\Role;
use App\Domain\User;
use App\Domain\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryReferralRepository;
use Tests\Support\InMemoryTrackingTokenRepository;

/**
 * The status link: hashed at rest, single use, expiring.
 *
 * These three properties are what make it safe to put in an SMS. The test checks
 * the stored record rather than the returned string, because the point of the
 * design is that the database never holds anything worth stealing.
 */
final class ReferralTrackingLinkTest extends TestCase
{
    private const NOW = '2026-11-09 13:00:00';

    private InMemoryTrackingTokenRepository $tokens;

    private InMemoryReferralRepository $referrals;

    private ReferralTrackingLink $links;

    private Referral $referral;

    protected function setUp(): void
    {
        $this->tokens = new InMemoryTrackingTokenRepository();
        $this->referrals = new InMemoryReferralRepository();
        $this->links = new ReferralTrackingLink($this->tokens, $this->referrals);

        $this->referral = $this->referrals->create(
            new Referral(
                id: 0,
                reference: 'RF-2026-0021',
                status: ReferralStatus::ClinicalReview,
                version: 1,
                patientName: 'Patient Three',
                patientPhone: '+251911000021',
                patientDob: null,
                guardianName: null,
                guardianPhone: null,
                guardianRelationship: null,
                procedureCode: 'fluoroscopic_biopsy',
                requestedUrgency: 'routine',
                clinicalSummary: null,
                targetSite: null,
                referringPhysicianName: null,
                referringFacility: null,
                referringPhysicianPhone: null,
                submittedByUserId: 3,
                submittedOnBehalfOf: 'self',
                createdAtUtc: self::NOW,
            ),
            new Actor(7, Role::Staff),
            self::NOW,
        );
    }

    public function testTheTokenIsRandomAndStoredOnlyAsAHash(): void
    {
        $token = $this->links->issue($this->staff(), $this->referral, self::NOW);

        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $token);
        self::assertNull($this->tokens->find($token));

        $stored = $this->tokens->find(hash('sha256', $token));
        self::assertNotNull($stored);
        self::assertSame($this->referral->id, $stored['referral_id']);
    }

    public function testRedemptionWorksOnce(): void
    {
        $token = $this->links->issue($this->staff(), $this->referral, self::NOW);

        $first = $this->links->redeem($token, self::NOW);
        self::assertNotNull($first);
        self::assertSame($this->referral->reference, $first->reference);

        self::assertNull($this->links->redeem($token, self::NOW));
    }

    public function testAnExpiredLinkIsRefused(): void
    {
        $token = $this->links->issue($this->staff(), $this->referral, self::NOW);
        $later = date('Y-m-d H:i:s', strtotime(self::NOW . ' +25 hours'));

        self::assertNull($this->links->redeem($token, $later));
    }

    public function testAMalformedTokenIsRefusedWithoutQueryingStorage(): void
    {
        $this->links->issue($this->staff(), $this->referral, self::NOW);

        self::assertNull($this->links->redeem('../../etc/passwd', self::NOW));
        self::assertNull($this->links->redeem('', self::NOW));
        self::assertNull($this->links->redeem(str_repeat('z', 64), self::NOW));
    }

    public function testTheLifetimeIsRecordedOnTheToken(): void
    {
        $this->links = new ReferralTrackingLink($this->tokens, $this->referrals, 30);

        $token = $this->links->issue($this->staff(), $this->referral, self::NOW);
        $stored = $this->tokens->find(hash('sha256', $token));

        self::assertNotNull($stored);
        self::assertSame('2026-11-09 13:30:00', $stored['expires_at']);
    }

    public function testOnlyATriageRoleMayIssueALink(): void
    {
        $physician = new User(55, 'referrer@example.test', 'Referrer', Role::Physician, true);

        $this->expectException(AuthorisationException::class);

        $this->links->issue($physician, $this->referral, self::NOW);
    }

    public function testAClosedReferralGetsNoStatusLink(): void
    {
        $closed = new Referral(
            id: $this->referral->id,
            reference: $this->referral->reference,
            status: ReferralStatus::Closed,
            version: 4,
            patientName: $this->referral->patientName,
            patientPhone: $this->referral->patientPhone,
            patientDob: null,
            guardianName: null,
            guardianPhone: null,
            guardianRelationship: null,
            procedureCode: $this->referral->procedureCode,
            requestedUrgency: 'routine',
            clinicalSummary: null,
            targetSite: null,
            referringPhysicianName: null,
            referringFacility: null,
            referringPhysicianPhone: null,
            submittedByUserId: 3,
            submittedOnBehalfOf: 'self',
            createdAtUtc: self::NOW,
        );

        $this->expectException(ValidationException::class);

        $this->links->issue($this->staff(), $closed, self::NOW);
    }

    private function staff(): User
    {
        return new User(7, 'clerk@example.test', 'Clerk', Role::Staff, true);
    }
}
