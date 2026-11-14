<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AuditAction;
use App\Domain\AuthorisationException;
use App\Domain\ChangePractitionerCredentialStatus;
use App\Domain\EndPractitionerPrivilege;
use App\Domain\GrantPractitionerPrivilege;
use App\Domain\PractitionerCredential;
use App\Domain\PractitionerCredentialStatus;
use App\Domain\RecordPractitionerCredential;
use App\Domain\Role;
use App\Domain\ValidationException;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryPractitionerCredentialRepository;
use Tests\Support\InMemoryProcedureRepository;
use Tests\Support\InMemoryUserRepository;
use Tests\Support\InlineTransaction;
use Tests\Support\RecordingAuditTrail;

/**
 * The register's write rules (Decision 6, AC-009, §16.3).
 *
 * Each refusal here is a rule the approved decision states, not a preference: an
 * account that cannot approve may not be credentialed, a credential has no open
 * end for "temporary", and a delegation has to name whose authority it borrows.
 */
final class PractitionerCredentialCommandTest extends TestCase
{
    private const NOW = '2026-11-14 09:00:00';

    private InMemoryUserRepository $users;

    private InMemoryPractitionerCredentialRepository $credentials;

    private InMemoryProcedureRepository $procedures;

    private RecordingAuditTrail $audit;

    private RecordPractitionerCredential $record;

    private ChangePractitionerCredentialStatus $changeStatus;

    private GrantPractitionerPrivilege $grant;

    private EndPractitionerPrivilege $end;

    private int $radiologistId = 0;

    private int $secondRadiologistId = 0;

    private int $clerkId = 0;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $hash = (new \App\Security\PasswordHasher())->hash('correct horse');

        $this->radiologistId = $this->users->seed('lead@example.test', 'Dr Lead', Role::Radiologist, $hash);
        $this->secondRadiologistId = $this->users->seed('cover@example.test', 'Dr Cover', Role::Radiologist, $hash);
        $this->clerkId = $this->users->seed('clerk@example.test', 'Clerk', Role::Staff, $hash);

        $this->credentials = new InMemoryPractitionerCredentialRepository();
        $this->procedures = new InMemoryProcedureRepository();
        $this->procedures->add(new \App\Domain\Procedure(
            code: 'image_guided_fnac',
            label: 'Image-guided fine needle aspiration (FNAC)',
            category: 'elective',
            defaultDurationMinutes: 30,
            requiredFields: null,
            fieldSetVersion: 1,
            approvedBy: null,
            approvedAtUtc: '',
            active: true,
        ));
        $this->audit = new RecordingAuditTrail();

        $this->record = new RecordPractitionerCredential(
            $this->credentials,
            $this->users,
            new Validator(),
            new InlineTransaction(),
            $this->audit,
        );
        $this->changeStatus = new ChangePractitionerCredentialStatus(
            $this->credentials,
            new InlineTransaction(),
            $this->audit,
        );
        $this->grant = new GrantPractitionerPrivilege(
            $this->credentials,
            $this->procedures,
            $this->users,
            new Validator(),
            new InlineTransaction(),
            $this->audit,
        );
        $this->end = new EndPractitionerPrivilege(
            $this->credentials,
            new InlineTransaction(),
            $this->audit,
        );
    }

    public function testTheDeskCannotRecordAVerification(): void
    {
        $this->expectException(AuthorisationException::class);

        $this->record->execute(new Actor($this->clerkId, Role::Staff), $this->radiologistId, $this->input());
    }

    /**
     * Decision 5 leaves the administrator technical authority only, so the
     * administrator cannot write the register that governs clinical approval.
     */
    public function testAnAdministratorCannotRecordAVerificationEither(): void
    {
        $this->expectException(AuthorisationException::class);

        $this->record->execute(new Actor(99, Role::Admin), $this->radiologistId, $this->input());
    }

    public function testAVerificationCannotBeRecordedForAnAccountThatCannotApprove(): void
    {
        try {
            $this->record->execute(
                new Actor($this->radiologistId, Role::Radiologist),
                $this->clerkId,
                $this->input(),
            );
            self::fail('A credential was recorded for an account that can never approve.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('practitioner_id', $exception->errors);
            self::assertStringContainsString(
                'does not hold clinical approval authority',
                $exception->errors['practitioner_id'],
            );
        }

        self::assertSame([], $this->credentials->forUser($this->clerkId));
    }

    public function testAVerificationWithoutAnEvidenceReferenceIsRefused(): void
    {
        try {
            $this->record->execute(
                new Actor($this->radiologistId, Role::Radiologist),
                $this->radiologistId,
                ['evidence_reference' => ''] + $this->input(),
            );
            self::fail('A verification was recorded with no evidence behind it.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('evidence_reference', $exception->errors);
        }
    }

    public function testAnEndDateBeforeTheStartIsRefused(): void
    {
        try {
            $this->record->execute(
                new Actor($this->radiologistId, Role::Radiologist),
                $this->radiologistId,
                ['effective_from' => '2026-06-01', 'expires_on' => '2026-05-01'] + $this->input(),
            );
            self::fail('A credential that expires before it takes effect was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('expires_on', $exception->errors);
        }
    }

    public function testARecordedVerificationCarriesItsVerifierAndADateAndIsAudited(): void
    {
        $credential = $this->record->execute(
            new Actor($this->secondRadiologistId, Role::Radiologist),
            $this->radiologistId,
            $this->input(),
            self::NOW,
        );

        self::assertGreaterThan(0, $credential->id);
        self::assertSame($this->radiologistId, $credential->userId);
        self::assertSame($this->secondRadiologistId, $credential->verifiedByUserId);
        self::assertSame(self::NOW, $credential->verifiedAtUtc);
        self::assertSame(PractitionerCredentialStatus::Verified, $credential->status);
        self::assertSame('2026-01-01', $credential->startsOn());

        $entries = $this->audit->entries();
        self::assertCount(1, $entries);
        self::assertSame(AuditAction::PractitionerCredentialRecorded, $entries[0]->action);
        self::assertSame('practitioner_credential', $entries[0]->objectType);
    }

    public function testSuspendingThenReinstatingWritesBothStates(): void
    {
        $credential = $this->verifiedCredential();

        $suspended = $this->changeStatus->execute(
            new Actor($this->radiologistId, Role::Radiologist),
            $credential->id,
            PractitionerCredentialStatus::Suspended,
        );
        self::assertSame(PractitionerCredentialStatus::Suspended, $suspended->status);

        $reinstated = $this->changeStatus->execute(
            new Actor($this->radiologistId, Role::Radiologist),
            $credential->id,
            PractitionerCredentialStatus::Verified,
        );
        self::assertSame(PractitionerCredentialStatus::Verified, $reinstated->status);

        $stored = $this->credentials->findCredential($credential->id);
        self::assertNotNull($stored);
        self::assertSame(PractitionerCredentialStatus::Verified, $stored->status);
        // The verification itself, the suspension and the reinstatement.
        self::assertCount(3, $this->audit->entries());
    }

    public function testRevokingRequiresAReasonAndIsFinal(): void
    {
        $credential = $this->verifiedCredential();
        $actor = new Actor($this->radiologistId, Role::Radiologist);

        try {
            $this->changeStatus->execute($actor, $credential->id, PractitionerCredentialStatus::Revoked, '');
            self::fail('A credential was revoked with no reason recorded.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('note', $exception->errors);
        }

        $this->changeStatus->execute(
            $actor,
            $credential->id,
            PractitionerCredentialStatus::Revoked,
            'Registration lapsed and the register shows no reinstatement.',
        );

        try {
            $this->changeStatus->execute($actor, $credential->id, PractitionerCredentialStatus::Verified);
            self::fail('A revoked credential was reinstated in place, erasing the finding.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('Record a new verification', $exception->errors['status']);
        }
    }

    public function testAPrivilegeCannotBeGrantedUnderASuspendedCredential(): void
    {
        $credential = $this->verifiedCredential();
        $actor = new Actor($this->radiologistId, Role::Radiologist);

        $this->changeStatus->execute($actor, $credential->id, PractitionerCredentialStatus::Suspended);

        try {
            $this->grant->execute($actor, $credential->id, $this->privilege());
            self::fail('A suspended credential acquired a new procedure privilege.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('credential', $exception->errors);
        }
    }

    public function testAPrivilegeMustNameAProcedureTheClinicHas(): void
    {
        $credential = $this->verifiedCredential();

        try {
            $this->grant->execute(
                new Actor($this->radiologistId, Role::Radiologist),
                $credential->id,
                ['procedure_code' => 'teleportation'] + $this->privilege(),
            );
            self::fail('A privilege named a procedure that is not in the catalogue.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('catalogue', $exception->errors['procedure_code']);
        }
    }

    public function testTemporaryCoverageHasToEnd(): void
    {
        $credential = $this->verifiedCredential();

        try {
            $this->grant->execute(
                new Actor($this->radiologistId, Role::Radiologist),
                $credential->id,
                [
                    'basis' => 'temporary_coverage',
                    'delegated_from_user_id' => (string) $this->secondRadiologistId,
                ] + $this->privilege(),
            );
            self::fail('Temporary coverage was recorded with no end date.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('effective_to', $exception->errors);
        }
    }

    public function testDelegationMustNameWhosePrivilegeIsLent(): void
    {
        $credential = $this->verifiedCredential();

        try {
            $this->grant->execute(
                new Actor($this->radiologistId, Role::Radiologist),
                $credential->id,
                ['basis' => 'delegated'] + $this->privilege(),
            );
            self::fail('A delegated privilege named nobody.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('delegated_from_user_id', $exception->errors);
        }
    }

    public function testAGrantedPrivilegeKeepsItsWindowAndItsBasis(): void
    {
        $credential = $this->verifiedCredential();

        $privilege = $this->grant->execute(
            new Actor($this->secondRadiologistId, Role::Radiologist),
            $credential->id,
            [
                'basis' => 'temporary_coverage',
                'delegated_from_user_id' => (string) $this->secondRadiologistId,
                'effective_from' => '2026-11-01',
                'effective_to' => '2026-11-30',
                'supervision' => 'Covering annual leave.',
            ] + $this->privilege(),
            self::NOW,
        );

        self::assertGreaterThan(0, $privilege->id);
        self::assertSame('image_guided_fnac', $privilege->procedureCode);
        self::assertSame('2026-11-01', $privilege->startsOn());
        self::assertSame('2026-11-30', $privilege->endsOn());
        self::assertSame($this->secondRadiologistId, $privilege->delegatedFromUserId);
        self::assertSame($this->secondRadiologistId, $privilege->grantedByUserId);
    }

    public function testEndingAPrivilegeClosesItsWindowAndIsRefusedTwice(): void
    {
        $credential = $this->verifiedCredential();
        $actor = new Actor($this->secondRadiologistId, Role::Radiologist);

        $privilege = $this->grant->execute($actor, $credential->id, $this->privilege());

        $ended = $this->end->execute($actor, $privilege->id, '2026-12-31', self::NOW);
        self::assertSame('2026-12-31', $ended->endsOn());

        $stored = $this->credentials->findPrivilege($privilege->id);
        self::assertNotNull($stored);
        self::assertSame('2026-12-31', $stored->endsOn());

        $this->expectException(ValidationException::class);
        $this->end->execute($actor, $privilege->id, '2027-01-31');
    }

    public function testAPrivilegeCannotEndBeforeItStarted(): void
    {
        $credential = $this->verifiedCredential();
        $actor = new Actor($this->secondRadiologistId, Role::Radiologist);
        $privilege = $this->grant->execute(
            $actor,
            $credential->id,
            ['effective_from' => '2026-11-01'] + $this->privilege(),
        );

        try {
            $this->end->execute($actor, $privilege->id, '2026-10-01');
            self::fail('A privilege ended before the day it began.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('ended_on', $exception->errors);
        }
    }

    private function verifiedCredential(): PractitionerCredential
    {
        return $this->record->execute(
            new Actor($this->radiologistId, Role::Radiologist),
            $this->radiologistId,
            $this->input(),
            self::NOW,
        );
    }

    /** @return array<string, string> */
    private function input(): array
    {
        return [
            'credential_type' => 'Specialist registration',
            'registration_number' => 'ET-IR-0042',
            'qualifications' => 'Interventional radiology fellowship.',
            'evidence_reference' => 'Registration register entry, checked 14 November 2026.',
            'effective_from' => '2026-01-01',
        ];
    }

    /** @return array<string, string> */
    private function privilege(): array
    {
        return [
            'procedure_code' => 'image_guided_fnac',
            'basis' => 'own',
            'effective_from' => '2026-01-01',
        ];
    }
}
