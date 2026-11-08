<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\CreateUserAccount;
use App\Domain\PhysicianProfile;
use App\Domain\PhysicianStatus;
use App\Domain\Role;
use App\Domain\ValidationException;
use App\Domain\VerifyPhysician;
use App\Security\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryPhysicianProfileRepository;
use Tests\Support\InMemoryUserRepository;

final class PhysicianVerificationTest extends TestCase
{
    private const NOW = '2026-11-09 08:00:00';

    private InMemoryPhysicianProfileRepository $profiles;

    private InMemoryUserRepository $users;

    private VerifyPhysician $verify;

    protected function setUp(): void
    {
        $this->profiles = new InMemoryPhysicianProfileRepository();
        $this->users = new InMemoryUserRepository();
        $this->verify = new VerifyPhysician($this->profiles);
    }

    public function testAnAdministratorVerifiesAPendingProfile(): void
    {
        $profile = $this->pending(userId: 11);

        $verified = $this->verify->execute(
            new Actor(1, Role::Admin),
            $profile->id,
            PhysicianStatus::Verified,
            'Practice number checked against the council register.',
            self::NOW,
        );

        self::assertSame(PhysicianStatus::Verified, $verified->status);
        self::assertTrue($verified->mayRefer());
        self::assertSame(1, $verified->verifiedByUserId);
        self::assertSame(self::NOW, $verified->verifiedAtUtc);
    }

    public function testAPhysicianCannotVerifyTheirOwnProfile(): void
    {
        $profile = $this->pending(userId: 11);

        $this->expectException(AuthorisationException::class);
        $this->expectExceptionMessageMatches('/own referring profile/i');

        $this->verify->execute(new Actor(11, Role::Admin), $profile->id, PhysicianStatus::Verified);
    }

    public function testAReceptionistCannotVerify(): void
    {
        $profile = $this->pending(userId: 11);

        $this->expectException(AuthorisationException::class);
        $this->verify->execute(new Actor(4, Role::Staff), $profile->id, PhysicianStatus::Verified);
    }

    public function testARejectionNeedsAReason(): void
    {
        $profile = $this->pending(userId: 11);

        $this->expectException(ValidationException::class);
        $this->verify->execute(new Actor(1, Role::Admin), $profile->id, PhysicianStatus::Rejected, 'no');
    }

    public function testASuspendedProfileStopsTheReferrerImmediately(): void
    {
        $profile = $this->verify->execute(
            new Actor(1, Role::Admin),
            $this->pending(userId: 11)->id,
            PhysicianStatus::Verified,
        );

        $suspended = $this->verify->execute(
            new Actor(1, Role::Admin),
            $profile->id,
            PhysicianStatus::Suspended,
            'Council registration lapsed.',
            self::NOW,
        );

        self::assertSame(PhysicianStatus::Suspended, $suspended->status);
        self::assertFalse($suspended->mayRefer());
    }

    public function testProvisioningCreatesAnAccountThatMustResetItsPassword(): void
    {
        $this->users->seed('admin@example.test', 'Admin', Role::Admin, 'hash');
        $command = new CreateUserAccount($this->users, new PasswordHasher());

        $result = $command->execute(
            new Actor(1, Role::Admin),
            ['email' => 'New.Doctor@Example.test', 'name' => 'Dr Hana Bekele', 'role' => 'physician'],
        );

        self::assertSame('new.doctor@example.test', $result['user']->email);
        self::assertSame(Role::Physician, $result['user']->role);
        self::assertGreaterThanOrEqual(PasswordHasher::MIN_LENGTH, strlen($result['temporary_password']));

        // The password is shown once; what is stored is a hash that verifies it.
        $stored = $this->users->findCredentialsByEmail('new.doctor@example.test');
        self::assertNotNull($stored);
        self::assertTrue((new PasswordHasher())->verify($result['temporary_password'], $stored['hash']));
    }

    public function testATakenEmailIsRefused(): void
    {
        $this->users->seed('taken@example.test', 'Existing', Role::Staff, 'hash');
        $command = new CreateUserAccount($this->users, new PasswordHasher());

        $this->expectException(ValidationException::class);
        $command->execute(
            new Actor(1, Role::Admin),
            ['email' => 'taken@example.test', 'name' => 'Someone Else', 'role' => 'staff'],
        );
    }

    public function testAnUnknownRoleIsRefusedRatherThanDefaulted(): void
    {
        $command = new CreateUserAccount($this->users, new PasswordHasher());

        $this->expectException(ValidationException::class);
        $command->execute(
            new Actor(1, Role::Admin),
            ['email' => 'new@example.test', 'name' => 'New Person', 'role' => 'superuser'],
        );
    }

    private function pending(int $userId): PhysicianProfile
    {
        return $this->profiles->create(
            new PhysicianProfile(
                id: 0,
                userId: $userId,
                practiceNumber: 'ET-MD-4821',
                institution: 'Yekatit 12 Hospital',
                specialty: 'Internal medicine',
                status: PhysicianStatus::Pending,
                verifiedByUserId: null,
                verifiedAtUtc: null,
                reviewNote: null,
                createdAtUtc: self::NOW,
            ),
            self::NOW,
        );
    }
}
