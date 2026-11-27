<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ReleaseGateFacts;
use App\Domain\ReleaseGates;
use App\Support\RetentionValidationRecord;
use PHPUnit\Framework\TestCase;

/**
 * §3.2's seven gates, and the two things that must stay true of the report.
 *
 * First: **the rows are the record's rows.** A gate that is renamed, merged or quietly dropped is a
 * gate that stops being checked while the report still looks complete, so the names, authorities and
 * required evidence are asserted against the decision record's own words.
 *
 * Second: **a gate cannot be talked into `met`.** `met` is reserved for evidence that was observed.
 * A set environment variable, a defined appointment with nobody in it, an encrypted archive with no
 * restore behind it — each of those is `partial`, because each is exactly the substitution of a
 * process artefact for a decision that this project has been caught making before.
 */
final class ReleaseGatesTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function facts(array $overrides = []): ReleaseGateFacts
    {
        $defaults = [
            'policyPagesTotal' => 3,
            'policyPagesPublished' => 0,
            'retentionActive' => false,
            'intussusceptionPublished' => false,
            'escalationRoutesTotal' => 5,
            'escalationRoutesVerified' => 0,
            'appointmentsDefined' => 5,
            'appointmentsNamed' => null,
            'backupKeyConfigured' => true,
            'encryptedArchives' => 1,
            'restoreDrillRecorded' => null,
            'credentialsTotal' => 8,
            'credentialsWithRotation' => 0,
            'credentialsWithCustodian' => 0,
            'recoveryKeyPresent' => false,
            'retentionValidation' => null,
            'retentionValidationDetail' => null,
        ];

        /** @var array<string, mixed> $merged */
        $merged = $overrides + $defaults;

        // Coerced rather than cast: `(int)` on a mixed value is a lie PHPStan is right to refuse, and in
        // a test helper it would quietly turn a typo in an override key into a zero that looks like a
        // real observation.
        $count = static fn (mixed $value): int => is_int($value) ? $value : 0;
        $flag = static fn (mixed $value): bool => $value === true;

        return new ReleaseGateFacts(
            policyPagesTotal: $count($merged['policyPagesTotal']),
            policyPagesPublished: $count($merged['policyPagesPublished']),
            retentionActive: $flag($merged['retentionActive']),
            intussusceptionPublished: $flag($merged['intussusceptionPublished']),
            escalationRoutesTotal: $count($merged['escalationRoutesTotal']),
            escalationRoutesVerified: $count($merged['escalationRoutesVerified']),
            appointmentsDefined: $count($merged['appointmentsDefined']),
            appointmentsNamed: is_int($merged['appointmentsNamed']) ? $merged['appointmentsNamed'] : null,
            backupKeyConfigured: $flag($merged['backupKeyConfigured']),
            encryptedArchives: $count($merged['encryptedArchives']),
            restoreDrillRecorded: is_bool($merged['restoreDrillRecorded'])
                ? $merged['restoreDrillRecorded']
                : null,
            credentialsTotal: $count($merged['credentialsTotal']),
            credentialsWithRotation: $count($merged['credentialsWithRotation']),
            credentialsWithCustodian: $count($merged['credentialsWithCustodian']),
            recoveryKeyPresent: $flag($merged['recoveryKeyPresent']),
            retentionValidation: is_string($merged['retentionValidation'])
                ? $merged['retentionValidation']
                : null,
            retentionValidationDetail: is_string($merged['retentionValidationDetail'])
                ? $merged['retentionValidationDetail']
                : null,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{state: string, detail: string}
     */
    private function gate(string $id, array $overrides = []): array
    {
        foreach ((new ReleaseGates($this->facts($overrides)))->assess() as $row) {
            if ($row['id'] === $id) {
                return ['state' => $row['state'], 'detail' => $row['detail']];
            }
        }

        self::fail('No gate named "' . $id . '".');
    }

    public function testTheReportCarriesTheRecordsSevenGatesInItsOrder(): void
    {
        $names = array_column((new ReleaseGates($this->facts()))->assess(), 'name');

        self::assertSame([
            'Policy publication',
            'Retention activation',
            'Intussusception service activation',
            'Escalation-route activation',
            'Operational coverage',
            'Backup production readiness',
            'Credential production readiness',
        ], $names);
    }

    public function testEachGateNamesTheAuthorityTheRecordGivesIt(): void
    {
        $expected = [
            'Policy publication' => 'Legal / Data Protection',
            'Retention activation' => 'Legal / Data Protection',
            'Intussusception service activation' => 'Clinical Approver / Receiving institution',
            'Escalation-route activation' => 'Operations / Clinical',
            'Operational coverage' => 'Operations',
            'Backup production readiness' => 'Engineering / Operations',
            'Credential production readiness' => 'Engineering / Owner',
        ];

        foreach ((new ReleaseGates($this->facts()))->assess() as $row) {
            self::assertSame($expected[$row['name']], $row['authority'], $row['name']);
            self::assertNotSame('', $row['evidence'], $row['name'] . ' does not state its evidence.');
        }
    }

    public function testNothingIsMetOnTheStateThisRecordActuallyDescribes(): void
    {
        $report = (new ReleaseGates($this->facts()))->assess();

        self::assertSame(7, ReleaseGates::blockedCount($report));
    }

    public function testPolicyPublicationIsMetOnlyWhenEveryPageIsLive(): void
    {
        self::assertSame('open', $this->gate('policy_publication')['state']);
        self::assertSame('open', $this->gate('policy_publication', ['policyPagesPublished' => 2])['state']);
        self::assertSame('met', $this->gate('policy_publication', ['policyPagesPublished' => 3])['state']);
    }

    public function testAnEmptySourceDoesNotReportPolicyPublicationAsMet(): void
    {
        $gate = $this->gate('policy_publication', ['policyPagesTotal' => 0, 'policyPagesPublished' => 0]);

        self::assertSame('open', $gate['state']);
        self::assertStringContainsString('No policy page', $gate['detail']);
    }

    /**
     * The flag is an attestation. Turning it on must not turn the gate green, because the thing the
     * gate requires — Legal having validated the periods — is not something a deployment can perform.
     */
    public function testTheRetentionFlagAloneIsPartialRatherThanMet(): void
    {
        self::assertSame('open', $this->gate('retention_activation')['state']);

        $gate = $this->gate('retention_activation', ['retentionActive' => true]);

        self::assertSame('partial', $gate['state']);
        self::assertStringContainsString('No validation record', $gate['detail']);
    }

    /**
     * And with a current record it is met — the branch that did not exist until 26 November 2026, when
     * the gate could only ever say `open` or `partial` and therefore never stop asking.
     */
    public function testAValidatedScheduleMeetsTheRetentionGate(): void
    {
        $gate = $this->gate('retention_activation', [
            'retentionActive' => true,
            'retentionValidation' => RetentionValidationRecord::CURRENT,
            'retentionValidationDetail' => 'Validated by Gemechisa Dalu on 2026-11-27.',
        ]);

        self::assertSame('met', $gate['state']);
        self::assertStringContainsString('Gemechisa Dalu', $gate['detail']);
        // Met is not the same as clean: the remaining unswept class stays named in the row so a green
        // gate cannot be read as "retention is finished". `security_and_audit` left this sentence on
        // 26 November 2026 when its sweep landed; a row that still listed it would be describing a gap
        // that no longer exists.
        self::assertStringContainsString('clinical_and_procedure', $gate['detail']);
        self::assertStringNotContainsString('security_and_audit', $gate['detail']);
    }

    public function testAStaleValidationLeavesTheGatePartialAndSaysWhy(): void
    {
        // The property the prose version could not have: the schedule moved, so the approval stopped
        // covering it, and the gate says so without anyone having to remember to revoke anything.
        $gate = $this->gate('retention_activation', [
            'retentionActive' => true,
            'retentionValidation' => RetentionValidationRecord::STALE,
            'retentionValidationDetail' => 'The schedule changed after 2026-11-27.',
        ]);

        self::assertSame('partial', $gate['state']);
        self::assertStringContainsString('The schedule changed after 2026-11-27.', $gate['detail']);
    }

    public function testTheIntussusceptionGateTurnsOnPublicationWhichD03MakesContingent(): void
    {
        self::assertSame('open', $this->gate('intussusception_activation')['state']);
        self::assertSame(
            'met',
            $this->gate('intussusception_activation', ['intussusceptionPublished' => true])['state']
        );
    }

    public function testEscalationRoutesAreCountedOnlyWhenActiveAndVerified(): void
    {
        self::assertSame('open', $this->gate('escalation_routes')['state']);

        $partly = $this->gate('escalation_routes', ['escalationRoutesVerified' => 4]);

        self::assertSame('open', $partly['state']);
        self::assertStringContainsString('4 of 5', $partly['detail']);

        self::assertSame(
            'met',
            $this->gate('escalation_routes', ['escalationRoutesVerified' => 5])['state']
        );
    }

    /**
     * A defined appointment with nobody in it is not coverage, and the report may not pretend that it
     * is merely "unobservable" in a way that reads like a pass.
     */
    public function testOperationalCoverageIsPartialWhileNoIndividualIsNamed(): void
    {
        $gate = $this->gate('operational_coverage');

        self::assertSame('partial', $gate['state']);
        self::assertStringContainsString('unobservable', $gate['detail']);

        self::assertSame(
            'met',
            $this->gate('operational_coverage', ['appointmentsNamed' => 5])['state']
        );
        self::assertSame(
            'open',
            $this->gate('operational_coverage', ['appointmentsNamed' => 3])['state']
        );
    }

    /** An encrypted archive is half of that gate; the restore test is the half nobody has run. */
    public function testBackupReadinessNeedsARestoreResultBeforeItIsMet(): void
    {
        self::assertSame(
            'open',
            $this->gate('backup_readiness', ['backupKeyConfigured' => false])['state']
        );
        self::assertSame(
            'open',
            $this->gate('backup_readiness', ['encryptedArchives' => 0])['state']
        );
        self::assertSame('partial', $this->gate('backup_readiness')['state']);
        self::assertSame(
            'met',
            $this->gate('backup_readiness', ['restoreDrillRecorded' => true])['state']
        );
    }

    public function testCredentialReadinessSeparatesTheInventoryFromTheHistory(): void
    {
        $partial = $this->gate('credential_readiness');

        self::assertSame('partial', $partial['state']);
        self::assertStringContainsString('no rotation on record', $partial['detail']);

        self::assertSame('met', $this->gate('credential_readiness', [
            'credentialsWithRotation' => 8,
            'credentialsWithCustodian' => 8,
            'recoveryKeyPresent' => true,
        ])['state']);
    }

    public function testAGateCannotBeMetByAnEmptyRegister(): void
    {
        $gate = $this->gate('credential_readiness', ['credentialsTotal' => 0]);

        self::assertSame('open', $gate['state']);
    }

    public function testEveryGateCarriesASentenceRatherThanACheckmark(): void
    {
        foreach ((new ReleaseGates($this->facts()))->assess() as $row) {
            self::assertGreaterThan(40, strlen($row['detail']), $row['name'] . ' explains nothing.');
        }
    }
}
